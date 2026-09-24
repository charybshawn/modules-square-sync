<?php

namespace Cultpantry\SquareSync\Console\Commands;

use Cultpantry\SquareSync\Actions\PullSquareInventoryChanges;
use Cultpantry\SquareSync\Actions\PullSquareSales;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Scheduled catch-up for everything the webhooks drive: applies any Square
 * sales to local stock that a dropped webhook missed, then records any new
 * sales and refunds. Both halves are incremental and idempotent, so running
 * this alongside the webhooks is always safe.
 */
class PullSquareSalesCommand extends Command
{
    protected $signature = 'square:pull-sales';

    protected $description = 'Apply new Square sales to local stock and record new Square sales and refunds.';

    public function handle(PullSquareInventoryChanges $pullInventoryChanges, PullSquareSales $pullSales): int
    {
        if (blank(config('square-sync.access_token'))) {
            $this->error('SQUARE_ACCESS_TOKEN is not configured.');

            return self::FAILURE;
        }

        $correlationId = (string) Str::uuid();

        $stock = $pullInventoryChanges->handle($correlationId);
        $this->info("Stock: {$stock['sold']} sale(s) and {$stock['restocked']} restock(s) applied.");

        $sales = $pullSales->handle(correlationId: $correlationId);
        $this->info("Records: {$sales['sales']} sale(s) and {$sales['refunds']} refund(s) recorded.");

        if ($sales['failed'] > 0) {
            $this->warn("{$sales['failed']} order(s) failed to record -- see square.sale_import_failed events.");
        }

        return self::SUCCESS;
    }
}
