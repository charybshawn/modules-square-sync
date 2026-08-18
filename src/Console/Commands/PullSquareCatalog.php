<?php

namespace Cultpantry\SquareSync\Console\Commands;

use App\Actions\RecordEvent;
use App\Models\Product;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Square\SquareClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Seeds the initial Product <-> Square mapping table by walking Square's
 * whole catalog once. PullSquareCatalogDelta handles everything after this:
 * it deliberately ignores objects it has no mapping for, so without this
 * command having run first, a delta pull has nothing to act on.
 *
 * Matching is by SKU, and only by SKU. Square's ITEM_VARIATION carries the
 * sku field, and products.sku is unique and auto-generated ('PRD-XXXXXXXX')
 * on create, which makes it the one identifier both systems already agree
 * on. Matching on name instead would be guesswork -- two products can share
 * a title, titles get edited on either side, and a wrong match here silently
 * points a real product's stock at the wrong Square variation, which is
 * exactly the failure this integration exists to prevent. Unmatched items
 * are reported for a human to link in the admin UI rather than guessed at.
 */
class PullSquareCatalog extends Command
{
    protected $signature = 'square:pull-catalog
                            {--dry-run : Report what would be linked without writing any mappings}';

    protected $description = 'Walk the full Square catalog and link its item variations to local products by SKU.';

    public function handle(SquareClient $client, RecordEvent $recordEvent): int
    {
        if (blank(config('square-sync.access_token'))) {
            $this->error('SQUARE_ACCESS_TOKEN is not configured.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $correlationId = (string) Str::uuid();

        $tally = ['linked' => 0, 'already_linked' => 0, 'unmatched' => 0, 'no_sku' => 0];
        $unmatched = [];

        // Variations only: Square tracks inventory counts against
        // ITEM_VARIATION, never the parent ITEM, so a mapping pointed at an
        // ITEM could never receive a count. The parent id is kept on the
        // mapping so the catalog push side can address the item itself.
        foreach ($client->catalog()->listItems(['ITEM_VARIATION']) as $object) {
            $variation = $object['item_variation_data'] ?? [];
            $sku = $variation['sku'] ?? null;

            if (blank($sku)) {
                $tally['no_sku']++;

                continue;
            }

            $product = Product::withTrashed()->where('sku', $sku)->first();

            if (! $product) {
                $tally['unmatched']++;
                $unmatched[] = "{$sku} ({$object['id']})";

                continue;
            }

            $existing = SquareObjectMapping::forSquareId($object['id'])->first();

            if ($existing) {
                $tally['already_linked']++;

                continue;
            }

            if (! $dryRun) {
                SquareObjectMapping::linkTo(
                    $product,
                    $object['id'],
                    'ITEM_VARIATION',
                    $variation['item_id'] ?? null,
                );
            }

            $tally['linked']++;

            $this->line("  linked {$product->title} <- {$sku}");
        }

        $this->table(
            ['Linked', 'Already linked', 'Unmatched in Square', 'Square variations without SKU'],
            [[$tally['linked'], $tally['already_linked'], $tally['unmatched'], $tally['no_sku']]],
        );

        if ($unmatched !== []) {
            $this->warn('Square variations with no local product of that SKU (link these by hand in /admin/square):');
            foreach (array_slice($unmatched, 0, 20) as $line) {
                $this->line("  {$line}");
            }
            if (count($unmatched) > 20) {
                $this->line('  ... and '.(count($unmatched) - 20).' more');
            }
        }

        // Not recorded on a dry run: the events table is an audit trail of
        // what actually changed, and a rehearsal changed nothing.
        if (! $dryRun) {
            $recordEvent->handle(
                type: 'square.catalog_pulled',
                description: "Square catalog import linked {$tally['linked']} product(s)",
                metadata: $tally,
                severity: 'info',
                direction: 'inbound',
                correlationId: $correlationId,
            );
        }

        if ($dryRun) {
            $this->info('Dry run -- no mappings were written.');
        }

        return self::SUCCESS;
    }
}
