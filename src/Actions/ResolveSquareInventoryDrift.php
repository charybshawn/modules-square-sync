<?php

namespace Cultpantry\SquareSync\Actions;

use Cultpantry\SquareSync\Contracts\LocalItem;
use Cultpantry\SquareSync\Jobs\PushInventoryCountJob;

/**
 * Resolves one already-linked item's drift from the sync-check results
 * table. Local stock is the source of truth, so the only resolution is to
 * push the current local count to Square -- the same PHYSICAL_COUNT job
 * link()'s 'local' choice uses.
 */
class ResolveSquareInventoryDrift
{
    public function handle(LocalItem $item): int
    {
        PushInventoryCountJob::dispatch($item->id, $item->stockQuantity)->afterCommit();

        return $item->stockQuantity;
    }
}
