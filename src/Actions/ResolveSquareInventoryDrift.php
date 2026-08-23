<?php

namespace Cultpantry\SquareSync\Actions;

use App\Actions\SyncInventory;
use App\Models\Product;
use App\Models\User;
use Cultpantry\SquareSync\Jobs\PushInventoryCountJob;
use Cultpantry\SquareSync\Models\SquareObjectMapping;

/**
 * Resolves one already-linked product's drift by the admin's explicit,
 * per-row choice in the sync-check results table -- same "which side is
 * correct?" decision link() asks when linking a new product, just applied
 * to a product that's already linked rather than creating a mapping.
 *
 * 'local' pushes the current stock_quantity to Square (same
 * PHYSICAL_COUNT job link()'s 'local' choice uses). 'square' pulls
 * Square's live count and applies it locally via SQUARE_INITIAL_SYNC --
 * deliberately distinct from SQUARE_PULL so the increase-guard (built to
 * stop *passive* Square writes, like the inbound webhook or an
 * unattended reconcile, from silently raising local stock) doesn't also
 * block this explicit, admin-confirmed choice.
 */
class ResolveSquareInventoryDrift
{
    public function __construct(
        private readonly FetchSquareInventoryCount $fetchSquareInventoryCount,
    ) {}

    public function handle(Product $product, SquareObjectMapping $mapping, string $source, ?User $actor = null): int
    {
        if ($source === 'local') {
            PushInventoryCountJob::dispatch($product->id, $product->stock_quantity)->afterCommit();

            return $product->stock_quantity;
        }

        $squareQuantity = $this->fetchSquareInventoryCount->handle($mapping->square_object_id);

        app(SyncInventory::class)->applyChange(
            product: $product,
            newQuantity: $squareQuantity,
            reason: SyncInventory::REASONS['SQUARE_INITIAL_SYNC'],
            actor: $actor,
            metadata: [
                'square_object_id' => $mapping->square_object_id,
                'source' => 'resolve_drift',
            ],
        );

        return $squareQuantity;
    }
}
