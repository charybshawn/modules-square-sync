<?php

namespace Cultpantry\SquareSync\Console\Commands;

use Cultpantry\SquareSync\Actions\ReconcileInventoryDrift;
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
 *
 * A thin CLI wrapper around ReconcileInventoryDrift -- the admin page's
 * "Run Sync Check" / "Pull Inventory Now" actions call that Action
 * directly, so both surfaces share one implementation instead of the web
 * path parsing this command's own table/text output.
 */
class ReconcileSquareInventory extends Command
{
    protected $signature = 'square:reconcile
        {--dry-run : Report drift without correcting it (this is also the default with no flags at all)}
        {--fix : Apply corrections for every drifted product found}';

    protected $description = 'Compare local stock quantities against Square inventory counts and report (or, with --fix, correct) drift.';

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
            ? "{$result['corrected']} product(s) corrected to match Square."
            : 'Report only -- pass --fix to correct local stock to match Square.');

        return self::SUCCESS;
    }
}
