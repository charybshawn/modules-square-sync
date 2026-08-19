<?php

namespace Cultpantry\SquareSync\Console\Commands;

use App\Actions\RecordEvent;
use App\Actions\SyncInventory;
use App\Models\Product;
use Cultpantry\SquareSync\Actions\GetSquareLocationId;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Square\SquareClient;
use Illuminate\Console\Command;

/**
 * Periodic drift check between local stock_quantity and Square's inventory
 * counts. This is the safety net for anything the webhook-driven pull
 * missed -- a dropped delivery, a Square outage during retries, a manual
 * count correction made directly in Square's dashboard that predates this
 * module ever being installed.
 *
 * Default behaviour (no flags, or --dry-run) only reports: it writes a
 * square.drift_detected event per drifted product and prints a table, but
 * never touches stock_quantity. Only --fix applies corrections. This
 * asymmetry is deliberate -- see CheckOutstandingWebhooks for the
 * app's existing precedent of "detect and escalate" commands that don't
 * auto-correct. An auto-correcting reconciler is exactly the kind of thing
 * that turns one bad API response into a whole-catalog inventory wipe.
 */
class ReconcileSquareInventory extends Command
{
    protected $signature = 'square:reconcile
        {--dry-run : Report drift without correcting it (this is also the default with no flags at all)}
        {--fix : Apply corrections for every drifted product found}';

    protected $description = 'Compare local stock quantities against Square inventory counts and report (or, with --fix, correct) drift.';

    public function __construct(
        private readonly SquareClient $client,
        private readonly SyncInventory $syncInventory,
        private readonly RecordEvent $recordEvent,
        private readonly GetSquareLocationId $getLocationId,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // --dry-run wins if both flags are somehow passed together --
        // report-only is the safe default, and there's no legitimate
        // reason for --dry-run to be silently overridden by --fix.
        $fix = $this->option('fix') && ! $this->option('dry-run');

        $mappings = SquareObjectMapping::linked()->with('mappable')->get()
            ->filter(fn (SquareObjectMapping $mapping) => $mapping->mappable instanceof Product);

        if ($mappings->isEmpty()) {
            $this->info('No linked Square product mappings to reconcile.');

            return self::SUCCESS;
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
            $rows[] = [$product->sku, $product->title, $squareQuantity, $localQuantity, $squareQuantity - $localQuantity];

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

            if ($fix) {
                $this->syncInventory->applyChange(
                    product: $product,
                    newQuantity: $squareQuantity,
                    reason: SyncInventory::REASONS['SQUARE_PULL'],
                    metadata: ['source' => 'square:reconcile'],
                );

                $mapping->markPulled();
                $corrected++;
            }
        }

        if ($rows === []) {
            $this->info('No drift detected -- local stock matches Square for every linked product.');

            return self::SUCCESS;
        }

        $this->table(['SKU', 'Product', 'Square Qty', 'Local Qty', 'Diff'], $rows);
        $this->warn("{$drifted} product(s) drifted from Square.");
        $this->line($fix
            ? "{$corrected} product(s) corrected to match Square."
            : 'Report only -- pass --fix to correct local stock to match Square.');

        return self::SUCCESS;
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
