<?php

namespace Cultpantry\SquareSync\Actions;

use App\Actions\RecordEvent;
use App\Actions\SyncInventory;
use App\Models\Product;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Square\SquareClient;

/**
 * Core drift-detection/correction logic shared by `square:reconcile` (CLI)
 * and the admin "Run Sync Check" / "Pull Inventory Now" actions -- factored
 * out so both surfaces report and correct drift identically, and the web
 * path gets real structured data back instead of parsing the console
 * command's own text/table output.
 *
 * Default (fix: false) only reports: writes a square.drift_detected event
 * per drifted product, never touches stock_quantity. fix: true applies
 * corrections via SyncInventory::applyChange(), which -- via its
 * SQUARE_PULL increase-guard -- can silently reject a correction that
 * would raise local stock; see each row's fix_applied for whether it
 * actually did.
 */
class ReconcileInventoryDrift
{
    public function __construct(
        private readonly SquareClient $client,
        private readonly SyncInventory $syncInventory,
        private readonly RecordEvent $recordEvent,
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
        $mappings = SquareObjectMapping::linked()->with('mappable')->get()
            ->filter(fn (SquareObjectMapping $mapping) => $mapping->mappable instanceof Product);

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
            /** @var Product $product */
            $product = $mapping->mappable;

            // Square omits a count row entirely for an object it has never
            // counted, rather than returning an explicit zero -- both mean
            // "as far as Square knows, there are none", so a missing entry
            // is treated the same as a 0 count.
            $squareQuantity = $squareQuantities[$mapping->square_object_id] ?? 0;
            $localQuantity = $product->stock_quantity;

            if ($squareQuantity === $localQuantity) {
                // Already in sync -- nothing to correct, but --fix did just
                // successfully check this mapping against Square, and
                // last_pulled_at should say so. Skipping this would leave a
                // mapping that's never once drifted looking like it's never
                // been checked at all (admin UI: "Last Pulled: Never"),
                // which is exactly backwards from what a "Pull Inventory
                // Now" click just did.
                if ($fix) {
                    $mapping->markPulled();
                }

                continue;
            }

            $drifted++;

            $this->recordEvent->handle(
                type: 'square.drift_detected',
                description: "{$product->title} stock drifted from Square (local {$localQuantity}, Square {$squareQuantity})",
                subject: $product,
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

            $applied = false;

            if ($fix) {
                // applyChange() rejects a SQUARE_PULL increase outright
                // (Square may only ever decrease local stock) -- compare
                // the returned quantity to what was requested so a
                // rejected increase isn't reported as applied.
                $appliedQuantity = $this->syncInventory->applyChange(
                    product: $product,
                    newQuantity: $squareQuantity,
                    reason: SyncInventory::REASONS['SQUARE_PULL'],
                    metadata: ['source' => 'square:reconcile'],
                );

                $mapping->markPulled();

                $applied = $appliedQuantity === $squareQuantity;

                if ($applied) {
                    $corrected++;
                }
            }

            $rows[] = [
                'product_id' => $product->id,
                'product_title' => $product->title,
                'sku' => $product->sku,
                'square_quantity' => $squareQuantity,
                'local_quantity' => $localQuantity,
                'difference' => $squareQuantity - $localQuantity,
                'fix_attempted' => $fix,
                'fix_applied' => $applied,
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

            $quantities[$count['catalog_object_id']] = (int) ($count['quantity'] ?? 0);
        }

        return $quantities;
    }
}
