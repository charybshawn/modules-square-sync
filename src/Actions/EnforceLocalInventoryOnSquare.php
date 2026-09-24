<?php

namespace Cultpantry\SquareSync\Actions;

use Cultpantry\SquareSync\Contracts\AuditLog;
use Cultpantry\SquareSync\Contracts\LocalCatalog;
use Cultpantry\SquareSync\Jobs\PushInventoryCountJob;
use Cultpantry\SquareSync\Models\SquareObjectMapping;

/**
 * Makes Square match local stock for the items an inventory.count.updated
 * webhook reported, pushing the local count wherever Square's differs.
 *
 * Runs after PullSquareInventoryChanges has applied any sales in the same
 * webhook, so by now local stock already reflects every sale Square has
 * logged -- any remaining difference is a change made directly on Square
 * (a recount, waste, a manual edit), which local stock deliberately
 * ignores and this overwrites.
 *
 * Terminates on its own: the push fires another count webhook whose count
 * now equals local, so nothing is pushed a second time. If a sale's change
 * hadn't surfaced in Square's (eventually consistent) change log yet, the
 * push briefly overwrites it on Square -- the next webhook's overlapping
 * pull then applies the sale locally and pushes again, converging.
 */
class EnforceLocalInventoryOnSquare
{
    public function __construct(
        private readonly ShouldSyncToSquare $shouldSync,
        private readonly GetSquareLocationId $getLocationId,
        private readonly LocalCatalog $catalog,
        private readonly AuditLog $auditLog,
    ) {}

    /**
     * @param  array<int, array>  $counts  data.object.inventory_counts from
     *                                     the webhook payload, e.g. ['catalog_object_id' => '...', 'state' =>
     *                                     'IN_STOCK', 'quantity' => '8', 'location_id' => '...'].
     * @return int how many items were pushed
     */
    public function handle(array $counts, ?string $correlationId = null, ?int $parentRef = null): int
    {
        // Same kill switch + credentials/location guard as every other
        // outbound path -- without a location there is nowhere to push.
        if (! $this->shouldSync->handle()) {
            return 0;
        }

        $locationId = $this->getLocationId->handle();
        $pushed = 0;

        foreach ($counts as $count) {
            // Only IN_STOCK is "units available to sell", the one number
            // local stock represents -- and only at the configured
            // location, the one PushInventoryCountJob writes to.
            if (($count['state'] ?? 'IN_STOCK') !== 'IN_STOCK') {
                continue;
            }

            if (filled($count['location_id'] ?? null) && $count['location_id'] !== $locationId) {
                continue;
            }

            $mapping = SquareObjectMapping::forSquareId($count['catalog_object_id'] ?? '')->linked()->first();
            $item = $mapping ? $this->catalog->find($mapping->mappable_id) : null;

            if (! $item || ! $item->tracksInventory) {
                continue;
            }

            $squareQuantity = (int) round((float) ($count['quantity'] ?? 0));

            if ($squareQuantity === $item->stockQuantity) {
                continue;
            }

            PushInventoryCountJob::dispatch($item->id, $item->stockQuantity)->afterCommit();
            $pushed++;

            $this->auditLog->record(
                type: 'square.manual_change_overridden',
                description: "{$item->title} changed on Square ({$squareQuantity}) -- reset to local stock ({$item->stockQuantity})",
                itemId: $item->id,
                metadata: [
                    'square_object_id' => $mapping->square_object_id,
                    'square_quantity' => $squareQuantity,
                    'local_quantity' => $item->stockQuantity,
                ],
                severity: 'warning',
                direction: 'outbound',
                correlationId: $correlationId,
                parentRef: $parentRef,
            );
        }

        return $pushed;
    }
}
