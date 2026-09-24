<?php

namespace Cultpantry\SquareSync\Jobs;

use Cultpantry\SquareSync\Actions\PullSquareSales;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs an incremental sales-record pull off the webhook's request path.
 * Queued rather than inline so a sales-record problem (Square's Orders API
 * down, a missing ORDERS_READ scope) can never fail -- and make Square
 * retry -- the inventory webhook that keeps stock correct.
 *
 * Unique: a burst of webhooks (several sales in a minute) only needs one
 * pull waiting in the queue; the watermark picks up everything since the
 * last run either way.
 */
class PullSquareSalesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 300;

    public function __construct(public readonly ?string $correlationId = null) {}

    public function handle(PullSquareSales $pullSquareSales): void
    {
        $pullSquareSales->handle(correlationId: $this->correlationId);
    }
}
