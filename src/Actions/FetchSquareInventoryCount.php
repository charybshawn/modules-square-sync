<?php

namespace Cultpantry\SquareSync\Actions;

use Cultpantry\SquareSync\Square\SquareClient;

/**
 * Live IN_STOCK count for a single Square catalog object at the
 * configured location -- used to preview and to apply an admin's explicit
 * "trust Square" choice at link time (SquareSyncController::linkPreview()/
 * link()). Deliberately synchronous, not queued: it's a single-item lookup
 * driven directly by an admin action, not a background sync.
 */
class FetchSquareInventoryCount
{
    public function __construct(
        private readonly SquareClient $client,
        private readonly GetSquareLocationId $getLocationId,
    ) {}

    public function handle(string $squareObjectId): int
    {
        $locationId = $this->getLocationId->handle();

        // Square omits a count row entirely for an object it has never
        // counted, rather than returning an explicit zero -- same
        // "missing means 0" convention ReconcileSquareInventory uses.
        foreach ($this->client->inventory()->batchRetrieveCounts([$squareObjectId], array_filter([$locationId])) as $count) {
            if (($count['state'] ?? 'IN_STOCK') === 'IN_STOCK') {
                return (int) ($count['quantity'] ?? 0);
            }
        }

        return 0;
    }
}
