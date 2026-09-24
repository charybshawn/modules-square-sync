<?php

namespace Cultpantry\SquareSync\Console\Commands;

use Cultpantry\SquareSync\Actions\PullSquareSales;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Backfills Square sales records from a start date -- e.g. the whole year
 * so far, run once after installing this. Records only: it never touches
 * stock, since those sales already happened and local stock already
 * reflects them. Safe to re-run (every sale and refund is recorded once),
 * which is also how an order that failed to record gets retried.
 */
class ImportSquareSales extends Command
{
    protected $signature = 'square:import-sales
                            {--since= : Import orders updated on or after this date (e.g. 2026-01-01)}';

    protected $description = 'Backfill Square sales and refunds into local sales records (never changes stock).';

    public function handle(PullSquareSales $pullSales): int
    {
        if (blank(config('square-sync.access_token'))) {
            $this->error('SQUARE_ACCESS_TOKEN is not configured.');

            return self::FAILURE;
        }

        try {
            $since = Carbon::parse((string) $this->option('since'))->startOfDay();
        } catch (Throwable) {
            $since = null;
        }

        if (blank($this->option('since')) || $since === null) {
            $this->error('Pass --since=YYYY-MM-DD.');

            return self::FAILURE;
        }

        $this->info("Importing Square sales since {$since->toDateString()}...");

        $result = $pullSales->handle(since: $since, correlationId: (string) Str::uuid());

        $this->info("{$result['sales']} sale(s) and {$result['refunds']} refund(s) recorded.");

        if ($result['failed'] > 0) {
            $this->warn("{$result['failed']} order(s) failed to record -- see square.sale_import_failed events, fix, and re-run.");
        }

        return self::SUCCESS;
    }
}
