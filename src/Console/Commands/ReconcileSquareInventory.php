<?php

namespace Cultpantry\SquareSync\Console\Commands;

use Cultpantry\SquareSync\Actions\ReconcileInventoryDrift;
use Illuminate\Console\Command;

/**
 * Periodic drift check between local stock and Square's inventory counts
 * -- the safety net for anything the webhook-driven enforcement missed (a
 * dropped delivery, a Square outage during retries, a count changed on
 * Square with no webhook to follow it).
 *
 * Local stock is the source of truth, so --fix pushes local counts to
 * Square; it never changes local stock. Default behaviour (no flags, or
 * --dry-run) only reports: it writes a square.drift_detected event per
 * drifted item and prints a table.
 *
 * A thin CLI wrapper around ReconcileInventoryDrift -- the admin page's
 * "Run Sync Check" action calls that Action directly, so both surfaces
 * share one implementation instead of the web path parsing this command's
 * own table/text output.
 */
class ReconcileSquareInventory extends Command
{
    protected $signature = 'square:reconcile
        {--dry-run : Report drift without correcting it (this is also the default with no flags at all)}
        {--fix : Push the local count to Square for every drifted item found}';

    protected $description = 'Compare local stock against Square inventory counts and report (or, with --fix, push local counts to Square to correct) drift.';

    public function __construct(private readonly ReconcileInventoryDrift $reconcileInventoryDrift)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // --dry-run wins if both flags are somehow passed together --
        // report-only is the safe default, and there's no legitimate
        // reason for --dry-run to be silently overridden by --fix.
        $fix = $this->option('fix') && ! $this->option('dry-run');

        $result = $this->reconcileInventoryDrift->handle($fix);

        if ($result['checked'] === 0) {
            $this->info('No linked Square product mappings to reconcile.');

            return self::SUCCESS;
        }

        if ($result['drifted'] === 0) {
            $this->info('No drift detected -- local stock matches Square for every linked product.');

            return self::SUCCESS;
        }

        $this->table(
            ['SKU', 'Product', 'Square Qty', 'Local Qty', 'Diff'],
            array_map(fn (array $row) => [
                $row['sku'],
                $row['product_title'],
                $row['square_quantity'],
                $row['local_quantity'],
                $row['difference'],
            ], $result['rows']),
        );

        $this->warn("{$result['drifted']} product(s) drifted from Square.");
        $this->line($fix
            ? "{$result['corrected']} product(s) queued to push local stock to Square."
            : 'Report only -- pass --fix to push local stock to Square.');

        return self::SUCCESS;
    }
}
