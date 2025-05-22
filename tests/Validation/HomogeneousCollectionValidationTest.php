<?php

namespace Spatie\LaravelData\Tests\Validation;

use Spatie\LaravelData\Attributes\HomogeneousCollectionItem;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\LaravelData\Tests\TestCase;

class HomogeneousCollectionValidationTest extends TestCase
{
    /** @test */
    public function it_generates_correct_rules_for_basic_homogeneous_collection()
    {
        $payload = [
            'items' => [
                ['name' => 'John Doe', 'age' => 30],
                ['name' => 'Jane Doe', 'age' => 25],
            ],
        ];

        $rules = ParentWithOptimizedCollectionData::getValidationRules($payload);

        $this->assertEquals([
            'items' => ['present', 'array'],
            'items.*.name' => [new Required(), new StringType(), new Max(100)],
            'items.*.age' => [new Required(), new IntegerType()],
        ], $rules);
    }

    /** @test */
    public function it_generates_correct_rules_for_non_optimized_collection_as_control()
    {
        $payload = [
            'items' => [
                ['name' => 'John Doe', 'age' => 30],
                ['name' => 'Jane Doe', 'age' => 25],
            ],
        ];

        $rules = ParentWithNonOptimizedCollectionData::getValidationRules($payload);

        $this->assertEquals([
            'items' => ['present', 'array'],
            'items.0.name' => [new Required(), new StringType(), new Max(100)],
            'items.0.age' => [new Required(), new IntegerType()],
            'items.1.name' => [new Required(), new StringType(), new Max(100)],
            'items.1.age' => [new Required(), new IntegerType()],
        ], $rules);
    }

    /** @test */
    public function it_generates_correct_rules_for_homogeneous_collection_with_compatible_static_rules()
    {
        $payload = [
            'items' => [
                ['field_a' => 'test_value_1'],
                ['field_a' => 'test_value_2'],
            ],
            'include_extra_rules' => true,
        ];

        $data = ParentWithOptimizedStaticRulesCollectionData::from($payload);
        $rules = $data::getValidationRules($payload);

        $this->assertEquals([
            'items' => ['present', 'array'],
            'include_extra_rules' => ['sometimes', 'boolean'],
            'items.*.field_a' => ['required', 'string', new Min(5)],
            'items.*.extra_field_from_static' => ['required', 'string', new Min(10)],
        ], $rules);

        $payloadWithoutFlag = [
            'items' => [
                ['field_a' => 'test_value_1'],
            ],
            'include_extra_rules' => false,
        ];
        $dataWithoutFlag = ParentWithOptimizedStaticRulesCollectionData::from($payloadWithoutFlag);
        $rulesWithoutFlag = $dataWithoutFlag::getValidationRules($payloadWithoutFlag);

        $this->assertEqualsCanonicalizing([ // Using canonicalizing for the outer array due to include_extra_rules potentially being absent or present with 'sometimes'
            'items' => ['present', 'array'],
            'include_extra_rules' => ['sometimes', 'boolean'],
            'items.*.field_a' => ['required', 'string', new Min(5)],
            // 'items.*.extra_field_from_static' will be absent here if not added by static rules
        ], $rulesWithoutFlag);
    }

    /** @test */
    public function it_generates_correct_rules_for_empty_homogeneous_collection()
    {
        $payload = ['items' => []];
        $rules = ParentWithOptimizedCollectionData::getValidationRules($payload);

        $this->assertEquals([
            'items' => ['present', 'array'],
            'items.*.name' => [new Required(), new StringType(), new Max(100)],
            'items.*.age' => [new Required(), new IntegerType()],
        ], $rules);
    }

    /** @test */
    public function it_generates_rules_based_on_empty_payload_for_homogeneous_collection_with_incorrect_static_rules()
    {
        $payload = [
            'items' => [
                ['name' => 'Special Item', 'value' => 20], // Would be min:11 if per-item
                ['name' => 'Regular Item', 'value' => 5],  // Would be max:10 if per-item
            ],
        ];

        $rules = ParentWithIncorrectStaticRulesCollectionData::getValidationRules($payload);

        $this->assertEquals([
            'items' => ['present', 'array'],
            'items.*.name' => ['required', 'string'],
            'items.*.value' => ['required', new IntegerType(), new Min(0), new Max(10)], // Fallback rule due to empty item payload
        ], $rules);
    }
}

// Fake Data Classes

#[HomogeneousCollectionItem]
class OptimizedItemData extends Data
{
    #[Required, StringType, Max(100)]
    public string $name;

    #[Required, IntegerType]
    public int $age;

    public function __construct(string $name, int $age)
    {
        $this->name = $name;
        $this->age = $age;
    }
}

class ParentWithOptimizedCollectionData extends Data
{
    /** @var DataCollection<OptimizedItemData> */
    public DataCollection $items;

    public function __construct(DataCollection $items)
    {
        $this->items = $items;
    }
}

class NonOptimizedItemData extends Data
{
    #[Required, StringType, Max(100)]
    public string $name;

    #[Required, IntegerType]
    public int $age;

    public function __construct(string $name, int $age)
    {
        $this->name = $name;
        $this->age = $age;
    }
}

class ParentWithNonOptimizedCollectionData extends Data
{
    /** @var DataCollection<NonOptimizedItemData> */
    public DataCollection $items;

    public function __construct(DataCollection $items)
    {
        $this->items = $items;
    }
}

#[HomogeneousCollectionItem]
class OptimizedItemWithStaticRulesData extends Data
{
    #[Required, StringType, Min(1)] // Base rule for field_a from attribute, overridden by static
    public string $field_a;

    public ?string $extra_field_from_static = null;

    public function __construct(string $field_a, ?string $extra_field_from_static = null)
    {
        $this->field_a = $field_a;
        $this->extra_field_from_static = $extra_field_from_static;
    }

    public static function rules(ValidationContext $context): array
    {
        $rules = [
            'field_a' => ['required', 'string', new Min(5)], // Overwrites attribute for field_a
        ];

        if ($context->fullPayload['include_extra_rules'] ?? false) {
            $rules['extra_field_from_static'] = ['required', 'string', new Min(10)];
        }
        // If 'extra_field_from_static' is not included via the condition,
        // and has no attributes, it won't have rules.
        // If it had attributes, they would be merged unless also overwritten here.

        return $rules;
    }
}

class ParentWithOptimizedStaticRulesCollectionData extends Data
{
    /** @var DataCollection<OptimizedItemWithStaticRulesData> */
    public DataCollection $items;

    public bool $include_extra_rules = false;

    public function __construct(DataCollection $items, bool $include_extra_rules = false)
    {
        $this->items = $items;
        $this->include_extra_rules = $include_extra_rules;
    }
}

#[HomogeneousCollectionItem]
class OptimizedItemWithIncorrectStaticRulesData extends Data
{
    public string $name;
    public int $value;

    public function __construct(string $name, int $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public static function rules(ValidationContext $context): array
    {
        $rules = [
            'name' => ['required', 'string'],
        ];

        // This logic depends on $context->payload, which is empty in the optimized path.
        if (($context->payload['value'] ?? 0) > 10) {
            $rules['value'] = ['required', new IntegerType(), new Min(11)];
        } else {
            // This branch is always taken in the optimized path.
            $rules['value'] = ['required', new IntegerType(), new Min(0), new Max(10)];
        }
        return $rules;
    }
}

class ParentWithIncorrectStaticRulesCollectionData extends Data
{
    /** @var DataCollection<OptimizedItemWithIncorrectStaticRulesData> */
    public DataCollection $items;

    public function __construct(DataCollection $items)
    {
        $this->items = $items;
    }
}

?>
