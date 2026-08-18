<?php

namespace Cultpantry\SquareSync\Observers;

use App\Actions\GetSiteSetting;
use App\Models\Product;
use Cultpantry\SquareSync\Actions\GetSquareLocationId;
use Cultpantry\SquareSync\Jobs\ArchiveSquareCatalogObjectJob;
use Cultpantry\SquareSync\Jobs\PushCatalogObjectJob;

/**
 * Outbound catalog half of the sync: local Product writes -> Square.
 * Registered explicitly via Product::observe() in
 * SquareSyncServiceProvider::boot() rather than the #[ObservedBy]
 * attribute, so every piece of this module's event wiring (this, plus the
 * StockUpdated listener) lives in one place.
 *
 * Every dispatch below is chained ->afterCommit(). Product writes commonly
 * happen inside a transaction (an admin controller updating several
 * things at once, a future bulk-edit action), and pushing mid-transaction
 * risks sending Square a change that then rolls back and never actually
 * happened locally.
 */
class ProductObserver
{
    public function __construct(
        private readonly GetSiteSetting $getSiteSetting,
        private readonly GetSquareLocationId $getLocationId,
    ) {}

    public function updated(Product $product): void
    {
        // stock_quantity is intentionally excluded here -- StockUpdated
        // (fired by App\Actions\SyncInventory::applyChange(), the app's
        // single stock-mutation funnel) and PushStockToSquare own that
        // field exclusively. Pushing it from this observer too would race
        // that listener and send Square two writes for one change.
        if (! $product->isDirty(['title', 'description', 'price'])) {
            return;
        }

        if (! $this->shouldSync()) {
            return;
        }

        PushCatalogObjectJob::dispatch($product->id)->afterCommit();
    }

    public function deleted(Product $product): void
    {
        if (! $this->shouldSync()) {
            return;
        }

        ArchiveSquareCatalogObjectJob::dispatch($product->id, archived: true)->afterCommit();
    }

    public function restored(Product $product): void
    {
        if (! $this->shouldSync()) {
            return;
        }

        ArchiveSquareCatalogObjectJob::dispatch($product->id, archived: false)->afterCommit();
    }

    /**
     * Mirrors PushStockToSquare's first two guard clauses -- module kill
     * switch, then credentials -- so this observer never dispatches a job
     * that would just fail (or silently no-op) once it actually runs.
     */
    private function shouldSync(): bool
    {
        if (! $this->getSiteSetting->handle('modules.cultpantry/square-sync.enabled', true)) {
            return false;
        }

        return filled(config('square-sync.access_token')) && filled($this->getLocationId->handle());
    }
}
