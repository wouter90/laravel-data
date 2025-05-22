<?php

namespace Spatie\LaravelData\Resolvers;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MergeValidationRules;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\Present;
use Spatie\LaravelData\Support\DataClass;
use Spatie\LaravelData\Support\DataConfig;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Validation\DataRules;
use Spatie\LaravelData\Support\Validation\EnsurePropertyMorphable;
use Spatie\LaravelData\Support\Validation\PropertyRules;
use Spatie\LaravelData\Support\Validation\RuleDenormalizer;
use Spatie\LaravelData\Support\Validation\RuleNormalizer;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\LaravelData\Support\Validation\ValidationPath;

class DataValidationRulesResolver
{
    public function __construct(
        protected DataConfig $dataConfig,
        protected RuleNormalizer $ruleAttributesResolver,
        protected RuleDenormalizer $ruleDenormalizer,
        protected DataMorphClassResolver $dataMorphClassResolver,
    ) {
    }

    public function execute(
        string $class,
        array $fullPayload,
        ValidationPath $path,
        DataRules $dataRules
    ): array {
        $dataClass = $this->dataConfig->getDataClass($class);

        if ($dataClass->isAbstract && $dataClass->propertyMorphable) {
            $payload = $path->isRoot()
                ? $fullPayload
                : Arr::get($fullPayload, $path->get(), []);

            $morphedClass = $this->dataMorphClassResolver->execute(
                $dataClass,
                [$payload],
            );

            $dataClass = $morphedClass
                ? $this->dataConfig->getDataClass($morphedClass)
                : $dataClass;
        }

        $withoutValidationProperties = [];

        foreach ($dataClass->properties as $dataProperty) {
            $propertyPath = $path->property($dataProperty->inputMappedName ?? $dataProperty->name);

            if ($this->shouldSkipPropertyValidation($dataProperty, $fullPayload, $propertyPath)) {
                $withoutValidationProperties[] = $dataProperty->name;

                continue;
            }

            if ($dataProperty->type->kind->isDataObject() || $dataProperty->type->kind->isDataCollectable()) {
                $this->resolveDataSpecificRules(
                    $dataProperty,
                    $fullPayload,
                    $path,
                    $propertyPath,
                    $dataRules
                );

                continue;
            }

            $rules = $this->inferRulesForDataProperty(
                $dataProperty,
                PropertyRules::create(),
                $fullPayload,
                $path,
            );

            if ($dataProperty->morphable) {
                $rules[] = new EnsurePropertyMorphable($dataClass);
            }

            $dataRules->add($propertyPath, $rules);
        }

        $this->resolveOverwrittenRules(
            $dataClass,
            $fullPayload,
            $path,
            $dataRules,
            $withoutValidationProperties
        );

        return $dataRules->rules;
    }

    protected function shouldSkipPropertyValidation(
        DataProperty $dataProperty,
        array $fullPayload,
        ValidationPath $propertyPath,
    ): bool {
        if ($dataProperty->validate === false) {
            return true;
        }

        if ($dataProperty->hasDefaultValue && Arr::has($fullPayload, $propertyPath->get()) === false) {
            return true;
        }

        return false;
    }

    protected function resolveDataSpecificRules(
        DataProperty $dataProperty,
        array $fullPayload,
        ValidationPath $path,
        ValidationPath $propertyPath,
        DataRules $dataRules,
    ): void {
        $isOptionalAndEmpty = $dataProperty->type->isOptional && Arr::has($fullPayload, $propertyPath->get()) === false;
        $isNullableAndEmpty = $dataProperty->type->isNullable && Arr::get($fullPayload, $propertyPath->get()) === null;

        if ($isOptionalAndEmpty || $isNullableAndEmpty) {
            $this->resolveToplevelRules(
                $dataProperty,
                $fullPayload,
                $path,
                $propertyPath,
                $dataRules
            );

            return;
        }

        if ($dataProperty->type->kind->isDataObject()) {
            $this->resolveDataObjectSpecificRules(
                $dataProperty,
                $fullPayload,
                $path,
                $propertyPath,
                $dataRules
            );

            return;
        }

        if ($dataProperty->type->kind->isDataCollectable()) {
            $this->resolveDataCollectionSpecificRules(
                $dataProperty,
                $fullPayload,
                $path,
                $propertyPath,
                $dataRules
            );
        }
    }

    protected function resolveDataObjectSpecificRules(
        DataProperty $dataProperty,
        array $fullPayload,
        ValidationPath $path,
        ValidationPath $propertyPath,
        DataRules $dataRules,
    ): void {
        $this->resolveToplevelRules(
            $dataProperty,
            $fullPayload,
            $path,
            $propertyPath,
            $dataRules
        );

        $this->execute(
            $dataProperty->type->dataClass,
            $fullPayload,
            $propertyPath,
            $dataRules,
        );
    }

    protected function resolveDataCollectionSpecificRules(
        DataProperty $dataProperty,
        array $fullPayload,
        ValidationPath $path,
        ValidationPath $propertyPath,
        DataRules $dataRules,
    ): void {
        $this->resolveToplevelRules(
            $dataProperty,
            $fullPayload,
            $path,
            $propertyPath,
            $dataRules,
            shouldBePresent: true
        );

        // Optimized path for collections with homogeneously typed items
        $itemDataClass = $this->dataConfig->getDataClass($dataProperty->type->dataClass);

        if ($itemDataClass->attributes->has(\Spatie\LaravelData\Attributes\HomogeneousCollectionItem::class)) {
            // Create a validation context for a generic item within the collection.
            // Payload is empty as rules should be generic for all items.
            // Path uses '*' to denote a generic item.
            $itemPath = ValidationPath::create($propertyPath->get() . '.*');
            $itemValidationContext = new ValidationContext(
                payload: [], // No specific item payload, rules are generic
                fullPayload: $fullPayload,
                path: $itemPath
            );

            $temporaryItemRules = DataRules::create();

            // Execute rule resolution once for the item type.
            $this->execute(
                $dataProperty->type->dataClass,
                $fullPayload, // Pass full payload for context, though item payload is empty
                $itemPath,
                $temporaryItemRules
            );

            // Transform and add the item rules to the main ruleset, prefixing with the collection path.
            foreach ($temporaryItemRules->rules as $fieldKey => $rulesForField) {
                // The $fieldKey from $temporaryItemRules->rules is already relative to the item path (e.g. 'title')
                // So we just need to prepend the collection property path.
                // Example: if $propertyPath is 'songs' and $fieldKey is 'title', final path is 'songs.*.title'
                // If $fieldKey is empty (e.g. for a root rule on the item itself), it becomes 'songs.*'
                // However, $this->execute already prepends the path, so $fieldKey might be 'songs.*.title'
                // We need to ensure the key is relative or correctly structured.

                // $this->execute prepends the path. If itemPath is 'collection.*',
                // rules in $temporaryItemRules will have keys like 'collection.*.property'.
                // We need to add these directly.
                $dataRules->add(ValidationPath::create($fieldKey), $rulesForField);
            }
        } else {
            // Default behavior: validate each item individually if not marked as homogeneous.
            $dataRules->addCollection($propertyPath, Rule::forEach(function (mixed $value, mixed $attribute) use ($fullPayload, $dataProperty) {
                if (! is_array($value)) {
                    return ['array'];
                }

                $rules = $this->execute(
                    $dataProperty->type->dataClass,
                    $fullPayload,
                    ValidationPath::create($attribute),
                    DataRules::create()
                );

                // The rules returned by ->execute() are already prefixed with the current item's path (e.g. collection.0.property)
                // We need to make them relative to the item for Rule::forEach to work correctly.
                // For example, if $attribute is 'songs.0' and a rule key is 'songs.0.title',
                // it should become 'title'.
                return collect($rules)->mapWithKeys(
                    fn (mixed $fieldRules, string $key) => [Str::after($key, "{$attribute}.") => $fieldRules]
                )->all();
            }));
        }
    }

    protected function resolveToplevelRules(
        DataProperty $dataProperty,
        array $fullPayload,
        ValidationPath $path,
        ValidationPath $propertyPath,
        DataRules $dataRules,
        bool $shouldBePresent = false
    ): void {
        $rules = [];

        if ($shouldBePresent) {
            $rules[] = Present::create();
        }

        $rules[] = ArrayType::create();

        $toplevelRules = $this->inferRulesForDataProperty(
            $dataProperty,
            PropertyRules::create(...$rules),
            $fullPayload,
            $path,
        );

        $dataRules->add($propertyPath, $toplevelRules);
    }


    protected function resolveOverwrittenRules(
        DataClass $class,
        array $fullPayload,
        ValidationPath $path,
        DataRules $dataRules,
        array $withoutValidationProperties
    ): void {
        if (! method_exists($class->name, 'rules')) {
            return;
        }

        $validationContext = new ValidationContext(
            $path->isRoot() ? $fullPayload : Arr::get($fullPayload, $path->get(), []),
            $fullPayload,
            $path
        );

        $overwrittenRules = app()->call([$class->name, 'rules'], ['context' => $validationContext]);
        $shouldMergeRules = $class->attributes->has(MergeValidationRules::class);

        foreach ($overwrittenRules as $key => $rules) {
            if (in_array($key, $withoutValidationProperties)) {
                continue;
            }

            $rules = collect(Arr::wrap($rules))
                ->map(fn (mixed $rule) => $this->ruleDenormalizer->execute($rule, $path))
                ->flatten()
                ->all();

            $shouldMergeRules
                ? $dataRules->merge($path->property($key), $rules)
                : $dataRules->add($path->property($key), $rules);
        }
    }

    protected function inferRulesForDataProperty(
        DataProperty $property,
        PropertyRules $rules,
        array $fullPayload,
        ValidationPath $path,
    ): array {
        $context = new ValidationContext(
            $path->isRoot() ? $fullPayload : Arr::get($fullPayload, $path->get(), null),
            $fullPayload,
            $path
        );

        foreach ($this->dataConfig->ruleInferrers as $inferrer) {
            $inferrer->handle($property, $rules, $context);
        }

        return $this->ruleDenormalizer->execute(
            $rules->all(),
            $path
        );
    }
}
