<?php

namespace Cultpantry\SquareSync\Database\Factories;

use Cultpantry\SquareSync\Contracts\LocalCatalog;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SquareObjectMapping>
 *
 * Autoloaded via this package's own PSR-4 rule for
 * Cultpantry\SquareSync\Database\Factories\ -> database/factories/, the same
 * way the costing package maps its seeders. $model is still set explicitly
 * below because Factory's default name-guessing only rewrites the App\Models
 * prefix and can't reach a package namespace.
 *
 * The package can't create a host item itself, so mappable_id defaults to
 * 0 -- callers that need a real linked item pass one via forItem().
 */
class SquareObjectMappingFactory extends Factory
{
    protected $model = SquareObjectMapping::class;

    public function definition(): array
    {
        return [
            'mappable_type' => fn () => app(LocalCatalog::class)->morphType(),
            'mappable_id' => 0,
            'square_object_id' => 'SQ_ITEM_'.$this->faker->unique()->regexify('[A-Z0-9]{18}'),
            'square_parent_object_id' => null,
            'square_object_type' => 'ITEM',
            'square_version' => $this->faker->numberBetween(1, 100),
            'last_pushed_at' => null,
            'last_pulled_at' => null,
            'last_pushed_hash' => null,
            'sync_status' => 'linked',
        ];
    }

    public function forItem(int $itemId): static
    {
        return $this->state(fn () => ['mappable_id' => $itemId]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['sync_status' => 'pending']);
    }

    public function conflict(): static
    {
        return $this->state(fn () => ['sync_status' => 'conflict']);
    }

    public function orphaned(): static
    {
        return $this->state(fn () => ['sync_status' => 'orphaned']);
    }

    /**
     * Maps an ITEM_VARIATION rather than the default ITEM, with
     * $parentObjectId as the owning CatalogItem's Square id.
     */
    public function forVariation(string $parentObjectId): static
    {
        return $this->state(fn () => [
            'square_object_type' => 'ITEM_VARIATION',
            'square_parent_object_id' => $parentObjectId,
        ]);
    }
}
