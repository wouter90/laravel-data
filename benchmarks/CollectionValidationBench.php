<?php

namespace Spatie\LaravelData\Benchmarks;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use Spatie\LaravelData\Attributes\HomogeneousCollectionItem;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Support\DataConfig;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * @BeforeMethods("ensureCacheIsPrimed")
 */
class CollectionValidationBench
{
    private DataConfig $dataConfig;
    private array $payloadForTen;
    private array $payloadForHundred;
    private array $payloadForThousand;

    public function __construct()
    {
        // Bootstrap Laravel if not already done by PhpBench configuration
        // This is often needed to use app() helper and Laravel services.
        // Adjust path as necessary if your vendor dir is elsewhere.
        if (!defined('LARAVEL_START')) { // A simple check
            require __DIR__ . '/../vendor/autoload.php';
            $app = require __DIR__ . '/../vendor/laravel/laravel/bootstrap/app.php';
            $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        }

        $this->dataConfig = app(DataConfig::class);

        $itemPayload = ['name' => 'John Doe', 'value' => 123];
        $this->payloadForTen = ['items' => array_fill(0, 10, $itemPayload)];
        $this->payloadForHundred = ['items' => array_fill(0, 100, $itemPayload)];
        $this->payloadForThousand = ['items' => array_fill(0, 1000, $itemPayload)];
    }

    public function ensureCacheIsPrimed(): void
    {
        $classesToCache = [
            BenchStandardItemData::class,
            ParentOfBenchStandardItemData::class,
            BenchOptimizedItemData::class,
            ParentOfBenchOptimizedItemData::class,
            BenchStandardItemWithRulesData::class,
            ParentOfBenchStandardItemWithRulesData::class,
            BenchOptimizedItemWithRulesData::class,
            ParentOfBenchOptimizedItemWithRulesData::class,
        ];

        foreach ($classesToCache as $class) {
            $this->dataConfig->getDataClass($class)->prepareForCache();
        }
        // Trigger the caching mechanism if it's not automatic after prepareForCache
        // In a typical Laravel app, `php artisan data:cache-structures` does this.
        // For benchmarks, we might need to ensure the cache store has the data.
        // This step might be environment-dependent or handled by a global setup.
        // For now, prepareForCache should load it into the in-memory DataConfig cache.
    }

    // Scenario 1: Baseline (Non-Optimized)
    #[Revs(100), Iterations(10), Groups(["baseline", "collection_10"])]
    public function benchBaselineNonOptimizedCollection10(): void
    {
        ParentOfBenchStandardItemData::getValidationRules($this->payloadForTen);
    }

    #[Revs(50), Iterations(5), Groups(["baseline", "collection_100"])]
    public function benchBaselineNonOptimizedCollection100(): void
    {
        ParentOfBenchStandardItemData::getValidationRules($this->payloadForHundred);
    }

    #[Revs(10), Iterations(2), Groups(["baseline", "collection_1000"])]
    public function benchBaselineNonOptimizedCollection1000(): void
    {
        ParentOfBenchStandardItemData::getValidationRules($this->payloadForThousand);
    }

    // Scenario 2: Optimized (HomogeneousCollectionItem)
    #[Revs(100), Iterations(10), Groups(["optimized", "collection_10"])]
    public function benchOptimizedCollection10(): void
    {
        ParentOfBenchOptimizedItemData::getValidationRules($this->payloadForTen);
    }

    #[Revs(50), Iterations(5), Groups(["optimized", "collection_100"])]
    public function benchOptimizedCollection100(): void
    {
        ParentOfBenchOptimizedItemData::getValidationRules($this->payloadForHundred);
    }

    #[Revs(10), Iterations(2), Groups(["optimized", "collection_1000"])]
    public function benchOptimizedCollection1000(): void
    {
        ParentOfBenchOptimizedItemData::getValidationRules($this->payloadForThousand);
    }

    // Scenario 3: Impact of Static rules() - Non-Optimized
    #[Revs(100), Iterations(10), Groups(["baseline_rules", "collection_10"])]
    public function benchBaselineWithRulesNonOptimizedCollection10(): void
    {
        ParentOfBenchStandardItemWithRulesData::getValidationRules($this->payloadForTen);
    }

    #[Revs(50), Iterations(5), Groups(["baseline_rules", "collection_100"])]
    public function benchBaselineWithRulesNonOptimizedCollection100(): void
    {
        ParentOfBenchStandardItemWithRulesData::getValidationRules($this->payloadForHundred);
    }

    #[Revs(10), Iterations(2), Groups(["baseline_rules", "collection_1000"])]
    public function benchBaselineWithRulesNonOptimizedCollection1000(): void
    {
        ParentOfBenchStandardItemWithRulesData::getValidationRules($this->payloadForThousand);
    }

    // Scenario 4: Impact of Static rules() - Optimized
    #[Revs(100), Iterations(10), Groups(["optimized_rules", "collection_10"])]
    public function benchOptimizedWithRulesCollection10(): void
    {
        ParentOfBenchOptimizedItemWithRulesData::getValidationRules($this->payloadForTen);
    }

    #[Revs(50), Iterations(5), Groups(["optimized_rules", "collection_100"])]
    public function benchOptimizedWithRulesCollection100(): void
    {
        ParentOfBenchOptimizedItemWithRulesData::getValidationRules($this->payloadForHundred);
    }

    #[Revs(10), Iterations(2), Groups(["optimized_rules", "collection_1000"])]
    public function benchOptimizedWithRulesCollection1000(): void
    {
        ParentOfBenchOptimizedItemWithRulesData::getValidationRules($this->payloadForThousand);
    }
}

// Data classes for benchmarks

class BenchStandardItemData extends Data
{
    #[Required, StringType]
    public string $name;
    #[Required, Min(0)]
    public int $value;

    public function __construct(string $name, int $value)
    {
        $this->name = $name;
        $this->value = $value;
    }
}

class ParentOfBenchStandardItemData extends Data
{
    /** @var DataCollection<BenchStandardItemData> */
    public DataCollection $items;
    public function __construct(DataCollection $items) { $this->items = $items; }
}

#[HomogeneousCollectionItem]
class BenchOptimizedItemData extends Data
{
    #[Required, StringType]
    public string $name;
    #[Required, Min(0)]
    public int $value;
    public function __construct(string $name, int $value) { $this->name = $name; $this->value = $value; }
}

class ParentOfBenchOptimizedItemData extends Data
{
    /** @var DataCollection<BenchOptimizedItemData> */
    public DataCollection $items;
    public function __construct(DataCollection $items) { $this->items = $items; }
}

class BenchStandardItemWithRulesData extends Data
{
    #[Required, StringType]
    public string $name;
    #[Required]
    public int $value;
    public function __construct(string $name, int $value) { $this->name = $name; $this->value = $value; }
    public static function rules(ValidationContext $context): array { return ['value' => [new Min(0)]]; }
}

class ParentOfBenchStandardItemWithRulesData extends Data
{
    /** @var DataCollection<BenchStandardItemWithRulesData> */
    public DataCollection $items;
    public function __construct(DataCollection $items) { $this->items = $items; }
}

#[HomogeneousCollectionItem]
class BenchOptimizedItemWithRulesData extends Data
{
    #[Required, StringType]
    public string $name;
    #[Required]
    public int $value;
    public function __construct(string $name, int $value) { $this->name = $name; $this->value = $value; }
    public static function rules(ValidationContext $context): array { return ['value' => [new Min(0)]]; }
}

class ParentOfBenchOptimizedItemWithRulesData extends Data
{
    /** @var DataCollection<BenchOptimizedItemWithRulesData> */
    public DataCollection $items;
    public function __construct(DataCollection $items) { $this->items = $items; }
}

?>
