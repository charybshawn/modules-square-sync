<?php

namespace Cultpantry\SquareSync\Actions;

use Cultpantry\SquareSync\Contracts\AuditLog;
use Cultpantry\SquareSync\Contracts\LocalCatalog;
use Cultpantry\SquareSync\Contracts\LocalItem;
use Cultpantry\SquareSync\Jobs\PushInventoryCountJob;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Square\SquareClient;

/**
 * Core drift-detection/correction logic shared by `square:reconcile` (CLI)
 * and the admin "Run Sync Check" action -- factored out so both surfaces
 * report and correct drift identically, and the web path gets real
 * structured data back instead of parsing the console command's own
 * text/table output.
 *
 * Local stock is the source of truth, so drift is only ever corrected in
 * one direction: fix: true pushes each drifted item's local count to
 * Square. Local stock is never touched here. Default (fix: false) only
 * reports, writing a square.drift_detected event per drifted item.
 */
class ReconcileInventoryDrift
{
    public function __construct(
        private readonly SquareClient $client,
        private readonly AuditLog $auditLog,
        private readonly LocalCatalog $catalog,
        private readonly GetSquareLocationId $getLocationId,
    ) {}

    /**
     * @return array{
     *     checked: int,
     *     drifted: int,
     *     corrected: int,
     *     rows: array<int, array{
     *         product_id: int,
     *         product_title: string,
     *         sku: string|null,
     *         square_quantity: int,
     *         local_quantity: int,
     *         difference: int,
     *         fix_attempted: bool,
     *         fix_applied: bool,
     *     }>,
     * }
     */
    public function handle(bool $fix = false): array
    {
        $mappings = SquareObjectMapping::linked()->forLocalCatalog()->get();
        $items = $this->catalog->findMany($mappings->pluck('mappable_id')->all());

        // Archived or vanished items have nothing to reconcile.
        $mappings = $mappings->filter(fn (SquareObjectMapping $mapping) => isset($items[$mapping->mappable_id]));

        if ($mappings->isEmpty()) {
            return ['checked' => 0, 'drifted' => 0, 'corrected' => 0, 'rows' => []];
        }

        $locationId = $this->getLocationId->handle();
        $catalogObjectIds = $mappings->pluck('square_object_id')->all();
        $squareQuantities = $this->fetchSquareQuantities($catalogObjectIds, $locationId);

        $rows = [];
        $drifted = 0;
        $corrected = 0;

        foreach ($mappings as $mapping) {
            /** @var LocalItem $item */
            $item = $items[$mapping->mappable_id];

            // Square omits a count row entirely for an object it has never
            // counted, rather than returning an explicit zero -- both mean
            // "as far as Square knows, there are none", so a missing entry
            // is treated the same as a 0 count.
            $squareQuantity = $squareQuantities[$mapping->square_object_id] ?? 0;
            $localQuantity = $item->stockQuantity;

            if ($squareQuantity === $localQuantity) {
                continue;
            }

            $drifted++;

            $this->auditLog->record(
                type: 'square.drift_detected',
                description: "{$item->title} stock drifted from Square (local {$localQuantity}, Square {$squareQuantity})",
                itemId: $item->id,
                metadata: [
                    'square_object_id' => $mapping->square_object_id,
                    'square_quantity' => $squareQuantity,
                    'local_quantity' => $localQuantity,
                    'difference' => $squareQuantity - $localQuantity,
                    'fixed' => $fix,
                ],
                severity: 'warning',
                direction: 'inbound',
            );

            if ($fix) {
                PushInventoryCountJob::dispatch($item->id, $localQuantity)->afterCommit();
                $corrected++;
            }

            $rows[] = [
                'product_id' => $item->id,
                'product_title' => $item->title,
                'sku' => $item->sku,
                'square_quantity' => $squareQuantity,
                'local_quantity' => $localQuantity,
                'difference' => $squareQuantity - $localQuantity,
                'fix_attempted' => $fix,
                'fix_applied' => $fix,
            ];
        }

        return [
            'checked' => $mappings->count(),
            'drifted' => $drifted,
            'corrected' => $corrected,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<int, string>  $catalogObjectIds
     * @return array<string, int> square_object_id => IN_STOCK quantity
     */
    private function fetchSquareQuantities(array $catalogObjectIds, ?string $locationId): array
    {
        $quantities = [];

        foreach ($this->client->inventory()->batchRetrieveCounts($catalogObjectIds, array_filter([$locationId])) as $count) {
            if (($count['state'] ?? 'IN_STOCK') !== 'IN_STOCK') {
                continue;
            }

            $quantities[$count['catalog_object_id']] = (int) round((float) ($count['quantity'] ?? 0));
        }

        return $quantities;
    }
}
