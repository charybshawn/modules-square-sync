<?php

namespace Cultpantry\SquareSync\Listeners;

use App\Actions\GetSiteSetting;
use App\Actions\SyncInventory;
use App\Events\StockUpdated;
use App\Models\Product;
use Cultpantry\SquareSync\Actions\GetSquareLocationId;
use Cultpantry\SquareSync\Jobs\PushInventoryCountJob;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Outbound half of the inventory sync: SyncInventory::applyChange() (the
 * app's single stock-mutation funnel) fires StockUpdated on every write,
 * and this listener decides whether that write belongs on Square too.
 *
 * Package namespaces aren't covered by Laravel's listener auto-discovery
 * (App\Providers\EventServiceProvider only scans App\Listeners by
 * convention), so this pairing is registered explicitly via
 * Event::listen() in SquareSyncServiceProvider::boot() instead.
 *
 * ShouldQueue here only governs how this guard-and-dispatch step itself is
 * scheduled -- the actual Square write happens in PushInventoryCountJob,
 * which this listener always dispatches ->afterCommit(). That matters
 * because SyncInventory::applyChange() dispatches StockUpdated from
 * *inside* its own DB::transaction(): without ->afterCommit(), the push
 * could fire on a quantity that then rolls back.
 */
class PushStockToSquare implements ShouldQueue
{
    public function __construct(
        private readonly GetSiteSetting $getSiteSetting,
        private readonly GetSquareLocationId $getLocationId,
    ) {}

    public function handle(StockUpdated $event): void
    {
        if (! $this->getSiteSetting->handle('modules.cultpantry/square-sync.enabled', true)) {
            return;
        }

        if (blank(config('square-sync.access_token')) || blank($this->getLocationId->handle())) {
            return;
        }

        // The echo-loop break: a change that came FROM Square (applied by
        // ApplyInventoryCountFromSquare with this exact reason) must never
        // be pushed straight back, or the two systems ping-pong the same
        // write forever.
        if ($event->reason === SyncInventory::REASONS['SQUARE_PULL']) {
            return;
        }

        $mapping = SquareObjectMapping::query()
            ->where('mappable_type', Product::class)
            ->where('mappable_id', $event->product->getKey())
            ->first();

        // Unmapped product -- there's nothing on Square to push a count to
        // yet (that's the catalog push's job, once it creates one). Not an
        // error, just nothing to do here.
        if (! $mapping) {
            return;
        }

        PushInventoryCountJob::dispatch($event->product->id, $event->newQuantity)->afterCommit();
    }
}
