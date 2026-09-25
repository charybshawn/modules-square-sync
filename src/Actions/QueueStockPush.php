<?php

namespace Cultpantry\SquareSync\Actions;

use Cultpantry\SquareSync\Jobs\PushInventoryCountJob;
use Cultpantry\SquareSync\Models\SquareObjectMapping;

/**
 * Outbound half of the inventory sync: the host calls this whenever a
 * local item's stock changes (from its own stock-change event listener),
 * and this decides whether that change belongs on Square too. The package
 * has no idea what the host's stock-change event is called -- the host
 * owns that wiring, and the echo-loop break that goes with it: a change
 * that came *from* Square's current count (LocalInventory::
 * setFromSquareAtLink) must never be forwarded here.
 *
 * Sales and restocks applied through LocalInventory *should* be forwarded:
 * pushing the resulting local count after a Square sale is what keeps
 * Square matched to local, even when someone edited the count on Square
 * in the meantime.
 *
 * The push itself is always dispatched ->afterCommit(): hosts typically
 * report stock changes from inside the transaction that made them, and
 * without it the push could fire on a quantity that then rolls back.
 */
class QueueStockPush
{
    public function __construct(private readonly ShouldSyncToSquare $shouldSync) {}

    public function handle(int $itemId, int $quantity): void
    {
        if (! $this->shouldSync->handle()) {
            return;
        }

        // Unlinked item -- there's nothing on Square to push a count to
        // yet (that's the catalog push's job, once it creates one). Not an
        // error, just nothing to do here.
        if (! SquareObjectMapping::forItem($itemId)->syncable()->exists()) {
            return;
        }

        PushInventoryCountJob::dispatch($itemId, $quantity)->afterCommit();
    }
}
