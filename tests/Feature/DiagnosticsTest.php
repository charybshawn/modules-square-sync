<?php

use App\Models\Product;
use App\Models\User;
use Cultpantry\SquareSync\Actions\RunSquareDiagnostics;
use Cultpantry\SquareSync\Actions\RunSquareTestSale;
use Cultpantry\SquareSync\Jobs\PushInventoryCountJob;
use Cultpantry\SquareSync\Models\SquareImportedSale;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Models\SquareWebhookEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
 * The Diagnostics panel: RunSquareDiagnostics' checks, and the sandbox
 * test sale (RunSquareTestSale) that follows a POS sale back to local
 * stock and the sales record.
 */

beforeEach(function () {
    config([
        'square-sync.access_token' => 'sq0atp-test-token',
        'square-sync.environment' => 'sandbox',
        'square-sync.location_id' => 'L_SYNC',
        'square-sync.base_url' => 'https://connect.squareupsandbox.com',
        'square-sync.webhook_signature_key' => 'key',
        'square-sync.notification_url' => 'https://shop.test/webhooks/square',
        'square-sync.retry_times' => 1,
    ]);

    Notification::fake();
    Mail::fake();
    Http::preventStrayRequests();

    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->product = Product::factory()->create(['title' => 'Pea Shoots', 'stock_quantity' => 10, 'track_inventory' => true]);
    SquareObjectMapping::linkTo($this->product->id, 'VAR_1', 'ITEM_VARIATION');
});

/**
 * @param  array<int, string>  $events
 */
function fakeDiagnosticsSquare(array $events = ['inventory.count.updated', 'catalog.version.updated', 'order.updated'], ?int $deliveryStatus = 200): void
{
    Http::fake([
        ...squareHealthFakes(),
        'connect.squareupsandbox.com/v2/catalog/batch-retrieve*' => Http::response([
            'objects' => [['type' => 'ITEM_VARIATION', 'id' => 'VAR_1', 'item_variation_data' => ['item_id' => 'ITEM_1']]],
            'related_objects' => [['type' => 'ITEM', 'id' => 'ITEM_1', 'item_data' => ['name' => 'Pea Shoots']]],
        ], 200),
        'connect.squareupsandbox.com/v2/webhooks/subscriptions/SUB_1/test' => Http::response([
            // Square's real answer: unwrapped, payload an object (not the
            // documented subscription_test_result wrapper). A null status is
            // Square recording no answer: only the event it sent.
            'notification_url' => 'https://shop.test/webhooks/square',
            'passes_filter' => true,
            'payload' => ['event_id' => 'EVT_TEST', 'type' => 'inventory.count.updated'],
            ...($deliveryStatus === null ? [] : ['status_code' => $deliveryStatus]),
        ], 200),
        'connect.squareupsandbox.com/v2/webhooks/subscriptions*' => Http::response(['subscriptions' => [
            ['id' => 'SUB_OTHER', 'enabled' => true, 'notification_url' => 'https://elsewhere.test/hook', 'event_types' => $events],
            ['id' => 'SUB_1', 'enabled' => true, 'notification_url' => 'https://shop.test/webhooks/square', 'event_types' => $events],
        ]], 200),
    ]);
}

function checkStatuses(array $diagnostics): array
{
    return collect($diagnostics['checks'])->pluck('status', 'key')->all();
}

describe('run checks', function () {
    it('passes every check on a healthy setup and asks Square to deliver a test webhook', function () {
        fakeDiagnosticsSquare();

        $diagnostics = app(RunSquareDiagnostics::class)->handle();

        expect(checkStatuses($diagnostics))->toMatchArray([
            'connection' => 'pass',
            'permissions' => 'pass',
            'location' => 'pass',
            'links' => 'pass',
            'webhook_settings' => 'pass',
            'webhook_subscription' => 'pass',
            'webhook_delivery' => 'pass',
        ])
            ->and($diagnostics['test_sale']['available'])->toBeTrue()
            ->and($diagnostics['test_sale']['products'][0])->toMatchArray(['id' => $this->product->id, 'stock' => 10]);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/webhooks/subscriptions/SUB_1/test')
            && $request['event_type'] === 'inventory.count.updated');
    });

    it('fails a subscription missing the inventory event, and skips the delivery test', function () {
        fakeDiagnosticsSquare(events: ['catalog.version.updated']);

        $diagnostics = app(RunSquareDiagnostics::class)->handle();
        $subscription = collect($diagnostics['checks'])->firstWhere('key', 'webhook_subscription');

        expect(checkStatuses($diagnostics))->toMatchArray(['webhook_subscription' => 'fail', 'webhook_delivery' => 'skip'])
            ->and($subscription['detail'])->toContain('inventory.count.updated');
    });

    it('explains a signature rejection on delivery', function () {
        fakeDiagnosticsSquare(deliveryStatus: 401);

        $delivery = collect(app(RunSquareDiagnostics::class)->handle()['checks'])->firstWhere('key', 'webhook_delivery');

        expect($delivery['status'])->toBe('fail')
            ->and($delivery['detail'])->toContain('SQUARE_WEBHOOK_SIGNATURE_KEY');
    });

    it('reads the delivery result in the documented wrapped shape too', function () {
        // Registered first so it wins over fakeDiagnosticsSquare()'s stub.
        Http::fake(['connect.squareupsandbox.com/v2/webhooks/subscriptions/SUB_1/test' => Http::response([
            'subscription_test_result' => ['id' => 'T1', 'status_code' => 401, 'payload' => json_encode(['event_id' => 'EVT_TEST'])],
        ], 200)]);
        fakeDiagnosticsSquare();

        $delivery = collect(app(RunSquareDiagnostics::class)->handle()['checks'])->firstWhere('key', 'webhook_delivery');

        expect($delivery['status'])->toBe('fail')
            ->and($delivery['detail'])->toContain('(401)');
    });

    it('passes delivery when Square records no answer but its test event arrived', function () {
        fakeDiagnosticsSquare(deliveryStatus: null);
        SquareWebhookEvent::create(['square_event_id' => 'EVT_TEST', 'event_type' => 'inventory.count.updated', 'payload' => [], 'status' => 'processed', 'processed_at' => now()]);

        $delivery = collect(app(RunSquareDiagnostics::class)->handle()['checks'])->firstWhere('key', 'webhook_delivery');

        expect($delivery['status'])->toBe('pass')
            ->and($delivery['detail'])->toContain('reached this app');
    });

    it('reports an unreachable app when Square records no answer and nothing arrived', function () {
        fakeDiagnosticsSquare(deliveryStatus: null);

        $delivery = collect(app(RunSquareDiagnostics::class)->handle()['checks'])->firstWhere('key', 'webhook_delivery');

        expect($delivery['status'])->toBe('fail')
            ->and($delivery['detail'])->toContain('couldn\'t reach');
    });

    it('returns the raw Square calls only when asked, without the access token', function () {
        fakeDiagnosticsSquare();

        $plain = $this->actingAs($this->admin)->postJson(route('admin.square.diagnostics'))->assertOk()->json();
        $debug = $this->actingAs($this->admin)->postJson(route('admin.square.diagnostics'), ['debug' => true])->assertOk()->json('debug');
        $delivery = collect($debug['square_calls'])->firstWhere('path', '/v2/webhooks/subscriptions/SUB_1/test');

        expect($plain)->not->toHaveKey('debug')
            ->and($delivery)->toMatchArray(['method' => 'POST', 'status' => 200])
            ->and($delivery['body'])->toMatchArray(['status_code' => 200])
            ->and($delivery)->toHaveKey('duration_ms')
            ->and($debug['config']['notification_url'])->toBe('https://shop.test/webhooks/square')
            ->and(json_encode($debug))->not->toContain('sq0atp-test-token');
    });

    it('flags a notification URL that doesn\'t point at this app', function () {
        config(['square-sync.notification_url' => 'https://shop.test/wrong/path']);
        fakeDiagnosticsSquare();

        expect(checkStatuses(app(RunSquareDiagnostics::class)->handle())['webhook_settings'])->toBe('fail');
    });

    it('never offers a test sale in production', function () {
        config(['square-sync.environment' => 'production']);
        fakeDiagnosticsSquare();

        $testSale = app(RunSquareDiagnostics::class)->handle()['test_sale'];

        expect($testSale['available'])->toBeFalse()
            ->and($testSale['reason'])->toContain('sandbox');
    });

    it('is served to admins from the page', function () {
        fakeDiagnosticsSquare();

        $this->actingAs($this->admin)
            ->postJson(route('admin.square.diagnostics'))
            ->assertOk()
            ->assertJsonPath('health.status', 'online')
            ->assertJsonCount(8, 'checks');
    });
});

function testSaleOrder(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 'TEST_ORDER',
        'location_id' => 'L_SYNC',
        'state' => 'OPEN',
        'source' => ['name' => 'Square Point of Sale'],
        'created_at' => now()->toIso8601String(),
        'closed_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
        'reference_id' => 'sync-test',
        'line_items' => [[
            'uid' => 'LINE_1', 'catalog_object_id' => 'VAR_1', 'name' => 'Pea Shoots', 'quantity' => '1',
            'base_price_money' => ['amount' => 550, 'currency' => 'CAD'],
            'gross_sales_money' => ['amount' => 550, 'currency' => 'CAD'],
            'total_money' => ['amount' => 550, 'currency' => 'CAD'],
        ]],
        'total_money' => ['amount' => 550, 'currency' => 'CAD'],
        'tenders' => [['type' => 'CARD', 'amount_money' => ['amount' => 550, 'currency' => 'CAD']]],
    ], $overrides);
}

function fakeTestSaleSquare(int $total = 550): void
{
    $completed = testSaleOrder(['state' => 'COMPLETED', 'total_money' => ['amount' => $total]]);

    Http::fake([
        'connect.squareupsandbox.com/v2/orders/search*' => Http::response(['orders' => [$completed]], 200),
        'connect.squareupsandbox.com/v2/orders/TEST_ORDER/pay' => Http::response(['order' => $completed], 200),
        'connect.squareupsandbox.com/v2/orders/TEST_ORDER' => Http::response(['order' => $completed], 200),
        'connect.squareupsandbox.com/v2/orders' => Http::response(['order' => testSaleOrder(['total_money' => ['amount' => $total]])], 200),
        'connect.squareupsandbox.com/v2/payments' => Http::response(['payment' => ['id' => 'PAY_1', 'status' => 'COMPLETED']], 200),
        // What Square's change log shows once the sale completes.
        'connect.squareupsandbox.com/v2/inventory/changes/batch-retrieve*' => Http::response(['changes' => [[
            'type' => 'ADJUSTMENT',
            'adjustment' => [
                'id' => 'ADJ_TEST', 'catalog_object_id' => 'VAR_1', 'location_id' => 'L_SYNC',
                'from_state' => 'IN_STOCK', 'to_state' => 'SOLD', 'quantity' => '1',
                'transaction_id' => 'TEST_ORDER', 'occurred_at' => now()->toIso8601String(),
            ],
        ]]], 200),
    ]);
}

describe('test sale', function () {
    beforeEach(function () {
        Queue::fake([PushInventoryCountJob::class]);
    });

    it('follows a sale from Square back to local stock and the sales record, then puts the unit back', function () {
        fakeTestSaleSquare();

        $run = app(RunSquareTestSale::class)->start($this->product->id);
        $steps = collect($run['steps'])->pluck('status', 'key');

        expect($steps->only(['order', 'paid'])->all())->toBe(['order' => 'pass', 'paid' => 'pass'])
            ->and($steps['webhook'])->toBe('pending')
            ->and($run['finished'])->toBeFalse();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/payments')
            && $request['source_id'] === 'cnon:card-nonce-ok'
            && $request['amount_money']['amount'] === 550
            && $request['order_id'] === 'TEST_ORDER');

        // Square's webhook arrives; the catch-up applies the sale and
        // records it (the same work the webhook's handler queues).
        SquareWebhookEvent::factory()->create(['event_type' => 'inventory.count.updated']);
        $run = app(RunSquareTestSale::class)->pullNow($run['id']);

        expect(collect($run['steps'])->pluck('status', 'key')->all())->toBe([
            'order' => 'pass', 'paid' => 'pass', 'webhook' => 'pass', 'stock' => 'pass', 'sale' => 'pass', 'restored' => 'pass',
        ])
            ->and($run['finished'])->toBeTrue()
            ->and($run['passed'])->toBeTrue()
            // Down by one, then put back.
            ->and($this->product->fresh()->stock_quantity)->toBe(10)
            ->and(SquareImportedSale::where('square_order_id', 'TEST_ORDER')->exists())->toBeTrue();

        Queue::assertPushed(PushInventoryCountJob::class, fn ($job) => $job->productId === $this->product->id && $job->quantity === 10);

        // Rechecking never puts the unit back twice.
        app(RunSquareTestSale::class)->check($run['id']);
        expect($this->product->fresh()->stock_quantity)->toBe(10);
    });

    it('pays a zero-total order without a card payment', function () {
        fakeTestSaleSquare(total: 0);

        app(RunSquareTestSale::class)->start($this->product->id);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/orders/TEST_ORDER/pay'));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/v2/payments'));
    });

    it('fails the webhook step after the timeout, and offers the manual pull', function () {
        fakeTestSaleSquare();
        $run = app(RunSquareTestSale::class)->start($this->product->id);

        $this->travel(RunSquareTestSale::TIMEOUT_SECONDS + 5)->seconds();
        $run = app(RunSquareTestSale::class)->check($run['id']);
        $steps = collect($run['steps'])->keyBy('key');

        expect($steps['webhook']['status'])->toBe('fail')
            ->and($steps['stock']['status'])->toBe('fail')
            ->and($run['finished'])->toBeTrue()
            ->and($run['passed'])->toBeFalse()
            ->and($run['can_pull'])->toBeTrue()
            ->and($this->product->fresh()->stock_quantity)->toBe(10);
    });

    it('refuses to run in production', function () {
        config(['square-sync.environment' => 'production']);

        $this->actingAs($this->admin)
            ->postJson(route('admin.square.test-sale.start'), ['product_id' => $this->product->id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Test sales only run in the sandbox environment.');

        Http::assertNothingSent();
    });

    it('resumes the latest run on the page', function () {
        fakeTestSaleSquare();
        $run = app(RunSquareTestSale::class)->start($this->product->id);

        expect(app(RunSquareTestSale::class)->latest()['id'])->toBe($run['id']);

        $this->actingAs($this->admin)
            ->postJson(route('admin.square.test-sale.check', $run['id']))
            ->assertOk()
            ->assertJsonPath('id', $run['id']);
    });
});
