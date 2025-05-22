---
title: Performance
weight: 15
---

Laravel Data is a powerful package that leverages PHP reflection to infer as much information as possible. While this approach provides a lot of benefits, it does come with a minor performance overhead. This overhead is typically negligible during development, but it can become noticeable in a production environment with a large number of data objects.

Fortunately, Laravel Data is designed to operate efficiently without relying on reflection. It achieves this by allowing you to cache the results of its complex analysis. This means that the performance cost is incurred only once, rather than on every request. By caching the analysis results before deploying your application to production, you ensure that a pre-analyzed, cached version of the data objects is used, significantly improving performance, especially for validation.

## Caching

Caching is the most crucial step for improving validation performance, especially when dealing with large datasets or collections of data objects. Laravel Data provides a command to cache the analysis results of your data objects. This command will analyze all of your data objects and store the results in a Laravel cache of your choice:

```
php artisan data:cache-structures
```

Running this command pre-analyzes and stores the structural information of data objects (like properties, types, attributes) and, importantly, any validation rules that can be inferred directly from these structures (e.g., type hints, validation attributes). This significantly reduces the overhead of reflection and rule inference during runtime validation.

That's it, the command will search for all the data objects in your application and cache the analysis results. Be sure to always run this command after creating or modifying a data object or when deploying your application to production.

## Static `rules()` Methods in Collections

When validating collections of data objects (e.g., `DataCollection<SongData>`), performance can be impacted by the complexity of static `rules()` methods within the item classes (e.g., `SongData`).

If you experience performance bottlenecks during the validation of large collections, review these static `rules()` methods. Complex or computationally intensive logic within these methods, especially if it varies significantly based on individual item payloads, can contribute to slower validation times. This is because the `rules()` method is executed for each item in the collection.

It's recommended to keep such `rules()` methods reasonably efficient to ensure optimal validation performance for collections.

## Optimizing Validation for Homogeneous Collections

For scenarios involving large collections where each item is of the same data object type and, critically, where the validation rules for each item are identical regardless of its specific content, Laravel Data offers a powerful optimization: the `#[Spatie\LaravelData\Attributes\HomogeneousCollectionItem]` attribute.

You can add this attribute to a Data class (e.g., `SongData`):

```php
use Spatie\LaravelData\Attributes\HomogeneousCollectionItem;
use Spatie\LaravelData\Data;

#[HomogeneousCollectionItem]
class SongData extends Data
{
    public function __construct(
        public string $title,
        public string $artist,
    ) {
    }

    public static function rules(ValidationContext $context): array
    {
        // Rule definitions
        return [
            'title' => ['required', 'string', 'max:255'],
            'artist' => ['required', 'string', 'max:255'],
        ];
    }
}
```

**How it Works:**

When `#[HomogeneousCollectionItem]` is present on `SongData`, and `SongData` is used within a collection (e.g., `DataCollection<SongData>`), the validation rule resolution mechanism changes. Instead of generating validation rules for `SongData` for *each individual item* in the collection, it generates them only *once*. These rules are then applied to every item. This significantly reduces redundant computations, leading to substantial performance improvements for large collections (e.g., hundreds or thousands of items).

**Crucial Usage Condition: Impact on `static rules()`**

The primary implication of using `#[HomogeneousCollectionItem]` relates to how you define `static rules(ValidationContext $context)` on the attributed Data class (e.g., `SongData`):

-   **The `static rules()` method MUST NOT rely on the item-specific payload (`$context->payload`) to determine the *structure* of the validation rules.**

During the optimized rule generation for a homogeneous collection, the `$context->payload` passed to the `rules()` method will be an **empty array (`[]`)**. This is because the rules are generated once for all items, not for a specific item.

However, the `rules()` method **CAN** still use:
    - `$context->fullPayload`: The entire payload being validated at the top level. This allows rules to be conditional based on other parts of the overall data.
    - `$context->path`: The validation path to the current item (e.g., `songs.*`).

**Example:**

Consider `SongData` marked with `#[HomogeneousCollectionItem]`.

```php
use Spatie\LaravelData\Attributes\HomogeneousCollectionItem;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Illuminate\Validation\Rule;

#[HomogeneousCollectionItem]
class SongData extends Data
{
    // ... constructor ...

    public static function rules(ValidationContext $context): array
    {
        $rules = [
            'title' => ['required', 'string'],
            // Allowed: Rule depends on the top-level payload
            'artist' => [Rule::requiredIf($context->fullPayload['collection_requires_artist'] ?? false)],
        ];

        // NOT Allowed (if attribute is used and this logic determines rule structure):
        // if (($context->payload['release_year'] ?? 0) < 1990) {
        //     $rules['title'][] = 'vintage_song_format'; // This structural change based on item payload is problematic
        // }
        // If you need rules to change based on individual item content like this,
        // do not use HomogeneousCollectionItem for that Data class.

        return $rules;
    }
}
```

If your `rules()` method tries to change which rules are applied or their parameters based on `$context->payload['some_specific_field_for_this_item']`, those conditions will not work as expected because `$context->payload` will be empty during the single rule-generation pass.

This is a trade-off: you gain significant performance for large, uniform collections, but you lose the ability to have highly dynamic, per-item rule structures within that collection defined via `$context->payload`.

**When to Use:**

-   You have large collections of data objects (e.g., `DataCollection<MyItemData>`).
-   All items in the collection are of the same type (`MyItemData`).
-   The validation rules for `MyItemData` are the same for every item and do not need to change based on the specific content of each individual item (i.e., its definition of `static rules()` does not depend on `$context->payload`).
-   You are experiencing performance bottlenecks during validation of these large collections.

By understanding this constraint, you can leverage `#[HomogeneousCollectionItem]` to optimize validation performance effectively.

## Configuration

The caching mechanism can be configured in the `data.php` config file. By default, the cache store is set to the default cache store of your application. You can change this to any other cache driver supported by Laravel. A prefix can also be set for the cache keys stored:

```php
'structure_caching' => [
    'cache' => [
        'store' => 'redis',
        'prefix' => 'laravel-data',
    ],
],
```

To find the data classes within your application, we're using the [php-structure-discoverer](https://github.com/spatie/php-structure-discoverer) package. This package allows you to configure the directories that will be searched for data objects. By default, the `app/data` directory is searched recursively. You can change this to any other directory or directories:

```php
'structure_caching' => [
    'directories' => [
        app_path('Data'),
    ],
],
```

Structure discoverer uses reflection (enabled by default) or a PHP parser to find the data objects. You can disable the reflection-based discovery and thus use the PHP parser discovery as such:

```php
'structure_caching' => [
    'reflection_discovery' => [
        'enabled' => false,
    ],
],
```

Since we cannot depend on reflection, we need to tell the parser what data objects are exactly and where to find them. This can be done by adding the laravel-data directory to the config directories:

```php
'structure_caching' => [
    'directories' => [
        app_path('Data'),
        base_path('vendor/spatie/laravel-data/src'),
    ],
],
```

When using reflection discovery, the base directory and root namespace can be configured as such if you're using a non-standard directory structure or namespace

```php
'structure_caching' => [
    'reflection_discovery' => [
        'enabled' => true,
        'base_path' => base_path(),
        'root_namespace' => null,
    ],
],
```

The caching mechanism can be disabled by setting the `enabled` option to `false`:

```php
'structure_caching' => [
    'enabled' => false,
],
```

You can read more about reflection discovery [here](https://github.com/spatie/php-structure-discoverer#parsers).

## Testing

When running tests, the cache is automatically disabled. This ensures that the analysis results are always up-to-date during development and testing. And that the cache won't interfere with your caching mocks.
