<?php

namespace Cultpantry\SquareSync\Actions;

use Cultpantry\SquareSync\Contracts\AuditLog;
use Cultpantry\SquareSync\Contracts\LocalCatalog;
use Cultpantry\SquareSync\Contracts\LocalInventory;
use Cultpantry\SquareSync\Jobs\PushInventoryCountJob;
use Cultpantry\SquareSync\Models\SquareImportedSale;
use Cultpantry\SquareSync\Models\SquareInventoryChange;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Models\SquareWebhookEvent;
use Cultpantry\SquareSync\Square\SquareClient;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * The end-to-end test: rings up one unit of a linked product on Square,
 * the way a POS sale would, then watches it travel back --
 *
 *  1. order created on Square
 *  2. order paid (sandbox test card) and completed
 *  3. Square notified this app (inventory.count.updated webhook)
 *  4. local stock went down by one
 *  5. the sale was recorded
 *
 * -- and finally puts the unit back in local stock (pushing the count to
 * Square too), so a test never costs real inventory.
 *
 * Sandbox only: in production the order and payment would be real.
 *
 * Steps 3-5 happen asynchronously, so start() returns immediately and the
 * page calls check() every few seconds until every step has an answer or
 * the run times out. A run is kept in the cache for a day, so a reload
 * picks it back up. If the webhook never comes, pullNow() runs the same
 * catch-up the scheduler does -- if stock and the sale then go through,
 * the processing works and only Square's notification is missing.
 */
class RunSquareTestSale
{
    public const TIMEOUT_SECONDS = 120;

    private const CACHE_TTL = 86400;

    private const LATEST_KEY = 'square_sync:test_sale:latest';

    /**
     * Square's sandbox test card that always approves.
     */
    private const SANDBOX_CARD_NONCE = 'cnon:card-nonce-ok';

    public function __construct(
        private readonly SquareClient $client,
        private readonly LocalCatalog $catalog,
        private readonly LocalInventory $inventory,
        private readonly GetSquareLocationId $getLocationId,
        private readonly PullSquareInventoryChanges $pullInventoryChanges,
        private readonly PullSquareSales $pullSales,
        private readonly AuditLog $auditLog,
    ) {}

    /**
     * @throws DomainException when a test sale can't run for this product
     */
    public function start(int $productId, mixed $actor = null): array
    {
        if (config('square-sync.environment') !== 'sandbox') {
            throw new DomainException('Test sales only run in the sandbox environment.');
        }

        $item = $this->catalog->find($productId);
        $mapping = SquareObjectMapping::forItem($productId)->linked()->first();

        if ($item === null || $mapping === null) {
            throw new DomainException('That product isn\'t linked to Square.');
        }

        if (! $item->tracksInventory || $item->stockQuantity < 1) {
            throw new DomainException("'{$item->title}' needs inventory tracking and at least 1 in stock.");
        }

        $run = [
            'id' => (string) Str::uuid(),
            'product_id' => $item->id,
            'product_title' => $item->title,
            'square_object_id' => $mapping->square_object_id,
            'stock_before' => $item->stockQuantity,
            'started_at' => now()->toIso8601String(),
            'order_id' => null,
            'order_state' => null,
            'amount' => null,
            'error' => null,
            'restored' => false,
            'pulled_manually' => false,
        ];

        try {
            $order = $this->client->orders()->create([
                'location_id' => $this->getLocationId->handle(),
                'reference_id' => 'sync-test',
                'line_items' => [['catalog_object_id' => $mapping->square_object_id, 'quantity' => '1']],
            ], $run['id'].':order')->json('order');

            $run['order_id'] = $order['id'];
            $run['amount'] = $order['total_money'] ?? ['amount' => 0, 'currency' => 'CAD'];

            if (($run['amount']['amount'] ?? 0) > 0) {
                $this->client->payments()->create([
                    'source_id' => self::SANDBOX_CARD_NONCE,
                    'amount_money' => $run['amount'],
                    'order_id' => $order['id'],
                    'location_id' => $order['location_id'],
                    'autocomplete' => true,
                    'note' => 'Square Sync test sale',
                ], $run['id'].':payment');
            } else {
                // Square won't take a payment for nothing; paying a zero
                // total order completes it without one.
                $this->client->orders()->pay($order['id'], [], $run['id'].':pay');
            }
        } catch (Throwable $e) {
            $run['error'] = $e->getMessage();
        }

        $this->auditLog->record(
            type: 'square.test_sale_started',
            description: "Square test sale of 1 × {$item->title}".($run['error'] ? " failed: {$run['error']}" : " (order {$run['order_id']})"),
            itemId: $item->id,
            actor: $actor,
            metadata: ['run_id' => $run['id'], 'square_order_id' => $run['order_id']],
            severity: $run['error'] ? 'warning' : 'info',
            direction: 'outbound',
        );

        $this->save($run);

        return $this->check($run['id']);
    }

    /**
     * The run's progress, advancing it as far as the evidence allows.
     */
    public function check(string $runId): array
    {
        $run = $this->find($runId);

        if ($run['order_id'] !== null && $run['order_state'] !== 'COMPLETED') {
            try {
                $run['order_state'] = $this->client->orders()->retrieve($run['order_id'])->json('order.state');
            } catch (Throwable) {
                // Reported as still pending; the next check retries.
            }
        }

        $steps = $this->steps($run);

        // Give the test unit back as soon as it's been taken, once.
        if (! $run['restored'] && $steps['stock']['status'] === 'pass') {
            $this->restore($run);
            $run['restored'] = true;
            $steps = $this->steps($run);
        }

        $this->save($run);

        return $this->present($run, $steps);
    }

    /**
     * The scheduler's catch-up, run now -- for when the webhook doesn't come.
     */
    public function pullNow(string $runId): array
    {
        $run = $this->find($runId);
        $correlationId = (string) Str::uuid();

        $this->pullInventoryChanges->handle($correlationId);
        $this->pullSales->handle(correlationId: $correlationId);

        $run['pulled_manually'] = true;
        $this->save($run);

        return $this->check($runId);
    }

    /**
     * The most recent run, if there's been one today.
     */
    public function latest(): ?array
    {
        $runId = Cache::get(self::LATEST_KEY);
        $run = $runId !== null ? Cache::get($this->key($runId)) : null;

        return $run !== null ? $this->present($run, $this->steps($run)) : null;
    }

    /**
     * @return array<string, array{label: string, status: 'pass'|'fail'|'pending'|'skip', detail: string}>
     */
    private function steps(array $run): array
    {
        $startedAt = Carbon::parse($run['started_at']);
        $timedOut = now()->diffInSeconds($startedAt, true) > self::TIMEOUT_SECONDS;
        $waiting = $timedOut ? 'fail' : 'pending';

        $steps = [];

        $steps['order'] = $run['order_id'] !== null
            ? $this->step('Order created on Square', 'pass', "Order {$run['order_id']} for 1 × {$run['product_title']}.")
            : $this->step('Order created on Square', 'fail', $run['error'] ?? 'Not created.');

        $steps['paid'] = match (true) {
            $run['order_id'] === null => $this->step('Paid and completed', 'skip', 'No order.'),
            $run['error'] !== null => $this->step('Paid and completed', 'fail', $run['error']),
            $run['order_state'] === 'COMPLETED' => $this->step('Paid and completed', 'pass', 'Paid with the sandbox test card'.$this->money($run['amount']).'.'),
            default => $this->step('Paid and completed', $waiting, $timedOut
                ? "Square still shows the order as {$run['order_state']}."
                : 'Waiting for Square to complete the order…'),
        };

        if ($steps['paid']['status'] !== 'pass') {
            foreach (['webhook' => 'Square notified this app', 'stock' => 'Local stock went down by 1', 'sale' => 'Sale recorded', 'restored' => 'Test unit put back'] as $key => $label) {
                $steps[$key] = $this->step($label, 'skip', 'Needs a completed order.');
            }

            return $steps;
        }

        $webhook = SquareWebhookEvent::query()
            ->where('event_type', 'inventory.count.updated')
            ->where('created_at', '>=', $startedAt)
            ->oldest()
            ->first();

        $steps['webhook'] = $webhook !== null
            ? $this->step('Square notified this app', 'pass', 'inventory.count.updated arrived '.$webhook->created_at->diffInSeconds($startedAt, true).'s after the sale.')
            : $this->step('Square notified this app', $waiting, $timedOut
                ? 'No inventory.count.updated webhook arrived within '.self::TIMEOUT_SECONDS.'s. Run the checks above to find out why'.($run['pulled_manually'] ? '.' : ', or pull from Square now to test the rest.')
                : 'Waiting for Square\'s inventory webhook…');

        $change = SquareInventoryChange::query()
            ->where('local_item_id', $run['product_id'])
            ->where('kind', 'sale')
            ->where('created_at', '>=', $startedAt)
            ->oldest()
            ->first();

        $steps['stock'] = $change !== null
            ? $this->step('Local stock went down by 1', 'pass', "{$run['product_title']}: {$run['stock_before']} → ".($run['stock_before'] + $change->quantity).($webhook === null && $run['pulled_manually'] ? ' (via the manual pull, not the webhook).' : '.'))
            : $this->step('Local stock went down by 1', $waiting, match (true) {
                ! $timedOut => 'Waiting…',
                $webhook !== null => 'The webhook arrived but no sale was applied. The Square item may not track inventory, or the sale was at a different location.',
                default => 'Nothing applied yet -- it depends on the webhook, or the catch-up pull.',
            });

        $sale = SquareImportedSale::query()->where('kind', 'sale')->where('square_order_id', $run['order_id'])->first();

        $steps['sale'] = $sale !== null
            ? $this->step('Sale recorded', 'pass', 'Recorded'.($sale->local_reference ? " as {$sale->local_reference}" : '').'.')
            : $this->step('Sale recorded', $waiting, $timedOut
                ? 'The order wasn\'t recorded. Sales are recorded by the pull the webhook queues (or the 15-minute catch-up) -- is the queue worker running?'
                : 'Waiting…');

        $steps['restored'] = match (true) {
            $run['restored'] => $this->step('Test unit put back', 'pass', "{$run['product_title']} is back to its stock before the test, and Square was updated to match."),
            $steps['stock']['status'] === 'fail' => $this->step('Test unit put back', 'skip', 'Nothing was taken.'),
            default => $this->step('Test unit put back', 'pending', 'Once the stock change arrives.'),
        };

        return $steps;
    }

    private function present(array $run, array $steps): array
    {
        $statuses = array_column($steps, 'status');

        return [
            'id' => $run['id'],
            'product_title' => $run['product_title'],
            'order_id' => $run['order_id'],
            'started_at' => $run['started_at'],
            'finished' => ! in_array('pending', $statuses, true),
            'passed' => ! in_array('pending', $statuses, true) && ! in_array('fail', $statuses, true),
            'can_pull' => ! $run['pulled_manually'] && $steps['paid']['status'] === 'pass' && in_array($steps['stock']['status'], ['pending', 'fail'], true),
            'steps' => collect($steps)->map(fn (array $step, string $key) => ['key' => $key, ...$step])->values()->all(),
        ];
    }

    private function restore(array $run): void
    {
        $this->inventory->applySquareRestock($run['product_id'], 1, [
            'source' => 'square_test_sale',
            'square_order_id' => $run['order_id'],
            'run_id' => $run['id'],
        ]);

        $item = $this->catalog->find($run['product_id']);

        if ($item !== null) {
            PushInventoryCountJob::dispatch($item->id, $item->stockQuantity)->afterCommit();
        }
    }

    private function find(string $runId): array
    {
        return Cache::get($this->key($runId)) ?? throw new DomainException('That test sale has expired. Start a new one.');
    }

    private function save(array $run): void
    {
        Cache::put($this->key($run['id']), $run, self::CACHE_TTL);
        Cache::put(self::LATEST_KEY, $run['id'], self::CACHE_TTL);
    }

    private function key(string $runId): string
    {
        return "square_sync:test_sale:{$runId}";
    }

    private function money(?array $money): string
    {
        return ($money['amount'] ?? 0) > 0
            ? ' ('.number_format($money['amount'] / 100, 2).' '.($money['currency'] ?? '').')'
            : ' (zero total)';
    }

    private function step(string $label, string $status, string $detail): array
    {
        return ['label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
