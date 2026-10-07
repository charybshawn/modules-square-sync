<?php

use App\Actions\GetSiteSetting;
use App\Actions\UpdateSiteSetting;
use App\Events\StockUpdated;
use App\Models\Product;
use Cultpantry\SquareSync\Actions\PullSquareInventoryChanges;
use Cultpantry\SquareSync\Jobs\PullSquareSalesJob;
use Cultpantry\SquareSync\Jobs\PushInventoryCountJob;
use Cultpantry\SquareSync\Models\SquareInventoryChange;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Models\SquareWebhookEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Feature tests through the live POST /webhooks/square route. Mirrors
 * the host app's tests/Feature/StripeWebhookTest.php shape but for Square's inbound
 * half: verify, claim (idempotency), switch on type, thread a correlation
 * id, mark processed/failed. See SquareWebhookController's docblock for the
 * full status-code contract this file asserts against.
 */

const INBOUND_NOTIFICATION_URL = 'https://example.test/webhooks/square';

const INBOUND_SIGNATURE_KEY = 'inbound-test-signature-key';

beforeEach(function () {
    config([
        'square-sync.notification_url' => INBOUND_NOTIFICATION_URL,
        'square-sync.webhook_signature_key' => INBOUND_SIGNATURE_KEY,
    ]);
});

/**
 * Posts a Square webhook payload to the live route with a correctly
 * computed signature (or a deliberately bad one), returning the raw body
 * that was actually sent so callers can assert against exactly what the
 * server parsed.
 */
function postSquareWebhook(array $payload, array $headers = [], ?string $rawBody = null): TestResponse
{
    $body = $rawBody ?? json_encode($payload);

    $signature = array_key_exists('HTTP_X_SQUARE_HMACSHA256_SIGNATURE', $headers)
        ? $headers['HTTP_X_SQUARE_HMACSHA256_SIGNATURE']
        : base64_encode(hash_hmac('sha256', INBOUND_NOTIFICATION_URL.$body, INBOUND_SIGNATURE_KEY, true));

    $serverHeaders = array_merge([
        'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $headers);

    return test()->call('POST', '/webhooks/square', [], [], [], $serverHeaders, $body);
}

/**
 * One entry of Square's inventory change log, as batch-retrieve returns it.
 */
function squareAdjustment(string $changeId, string $from, string $to, int $quantity, array $extra = []): array
{
    return [
        'type' => 'ADJUSTMENT',
        'adjustment' => array_merge([
            'id' => $changeId,
            'catalog_object_id' => 'SQ_ITEM_MAPPED',
            'location_id' => 'L_TEST',
            'from_state' => $from,
            'to_state' => $to,
            'quantity' => (string) $quantity,
            'occurred_at' => now()->toIso8601String(),
        ], $extra),
    ];
}

/**
 * Fakes Square's change log (returning $changes) and accepts any push.
 */
function fakeSquareChangeLog(array $changes): void
{
    Http::preventStrayRequests();
    Http::fake([
        'connect.squareupsandbox.com/v2/inventory/changes/batch-retrieve*' => Http::response(['changes' => $changes], 200),
        'connect.squareupsandbox.com/v2/inventory/changes/batch-create*' => Http::response(['counts' => []], 200),
    ]);
}

function squareInventoryPayload(string $eventId, array $overrides = []): array
{
    return array_merge([
        'event_id' => $eventId,
        'type' => 'inventory.count.updated',
        'data' => [
            'object' => [
                'inventory_counts' => [
                    [
                        'catalog_object_id' => 'SQ_ITEM_MAPPED',
                        'state' => 'IN_STOCK',
                        'quantity' => '17',
                        'location_id' => 'L_TEST',
                    ],
                ],
            ],
        ],
    ], $overrides);
}

describe('signature validation', function () {
    it('returns 200 for a request with a valid signature', function () {
        $eventId = (string) Str::uuid();
        $payload = ['event_id' => $eventId, 'type' => 'some.unhandled.type'];

        postSquareWebhook($payload)->assertStatus(200);
    });

    it('returns 401 for a tampered body', function () {
        $originalBody = json_encode(['event_id' => (string) Str::uuid(), 'type' => 'some.unhandled.type']);
        $signature = base64_encode(hash_hmac('sha256', INBOUND_NOTIFICATION_URL.$originalBody, INBOUND_SIGNATURE_KEY, true));

        $tamperedBody = json_encode(['event_id' => (string) Str::uuid(), 'type' => 'some.unhandled.type', 'extra' => 'injected']);

        test()->call('POST', '/webhooks/square', [], [], [], [
            'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $tamperedBody)->assertStatus(401);
    });

    it('returns 400 when the signature header is missing', function () {
        $body = json_encode(['event_id' => (string) Str::uuid(), 'type' => 'some.unhandled.type']);

        test()->call('POST', '/webhooks/square', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(400);
    });

    it('is excluded from csrf protection', function () {
        $body = json_encode(['event_id' => (string) Str::uuid(), 'type' => 'some.unhandled.type']);

        $response = test()->call('POST', '/webhooks/square', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        expect($response->status())->not->toBe(419);
    });
});

/*
 * The webhook's own counts are never applied locally -- they can't tell a
 * sale from a recount. The webhook triggers a read of Square's change log;
 * only sale movements (and the refunds that reverse them) touch local
 * stock, each exactly once. Anything else Square reports is overwritten
 * on Square with the local count.
 */
describe('inventory.count.updated', function () {
    beforeEach(function () {
        config([
            'square-sync.access_token' => 'sq0atp-test-token',
            'square-sync.location_id' => 'L_TEST',
            'square-sync.base_url' => 'https://connect.squareupsandbox.com',
            'square-sync.version' => '2025-01-23',
            'square-sync.retry_times' => 2,
            'square-sync.retry_sleep_ms' => 5,
        ]);

        // Sales *records* are a separate, queued pull -- covered in
        // SalesRecordingTest. Faked here so these tests stay about stock.
        Queue::fake([PullSquareSalesJob::class]);

        $this->product = Product::factory()->create(['stock_quantity' => 30, 'track_inventory' => true]);
        SquareObjectMapping::linkTo($this->product->id, 'SQ_ITEM_MAPPED', 'ITEM_VARIATION');
    });

    it('queues a sales-record pull without running it on the request path', function () {
        fakeSquareChangeLog([]);

        postSquareWebhook(squareInventoryPayload((string) Str::uuid()))->assertStatus(200);

        Queue::assertPushed(PullSquareSalesJob::class);
    });

    it('applies a Square sale as a decrement and records it in the ledger', function () {
        fakeSquareChangeLog([
            squareAdjustment('CHG_SALE_1', 'IN_STOCK', 'SOLD', 2, ['transaction_id' => 'ORDER_1']),
        ]);

        postSquareWebhook(squareInventoryPayload((string) Str::uuid(), [
            'data' => ['object' => ['inventory_counts' => [
                ['catalog_object_id' => 'SQ_ITEM_MAPPED', 'state' => 'IN_STOCK', 'quantity' => '28', 'location_id' => 'L_TEST'],
            ]]],
        ]))->assertStatus(200);

        expect($this->product->fresh()->stock_quantity)->toBe(28);

        $ledger = SquareInventoryChange::where('square_change_id', 'CHG_SALE_1')->first();
        expect($ledger->kind)->toBe('sale')
            ->and($ledger->quantity)->toBe(-2)
            ->and($ledger->transaction_id)->toBe('ORDER_1')
            ->and($ledger->local_item_id)->toBe($this->product->id);
    });

    it('tags the stock change square_sale and pushes the resulting local count back to Square', function () {
        Event::fake([StockUpdated::class]);
        fakeSquareChangeLog([squareAdjustment('CHG_SALE_TAG', 'IN_STOCK', 'SOLD', 1)]);

        postSquareWebhook(squareInventoryPayload((string) Str::uuid()))->assertStatus(200);

        Event::assertDispatched(StockUpdated::class, fn (StockUpdated $event) => $event->product->id === $this->product->id
            && $event->reason === 'square_sale'
            && $event->newQuantity === 29);
    });

    it('applies the same change once even when two webhooks both see it', function () {
        fakeSquareChangeLog([squareAdjustment('CHG_SALE_DUP', 'IN_STOCK', 'SOLD', 3)]);

        // Two *different* deliveries (not a retry of one), both of whose
        // pulls read the same overlapping window of the change log.
        postSquareWebhook(squareInventoryPayload((string) Str::uuid()))->assertStatus(200);
        postSquareWebhook(squareInventoryPayload((string) Str::uuid()))->assertStatus(200);

        expect($this->product->fresh()->stock_quantity)->toBe(27);
        expect(SquareInventoryChange::count())->toBe(1);
    });

    it('treats a replayed event_id as a duplicate without touching stock again', function () {
        fakeSquareChangeLog([squareAdjustment('CHG_SALE_REPLAY', 'IN_STOCK', 'SOLD', 4)]);

        $payload = squareInventoryPayload((string) Str::uuid());

        postSquareWebhook($payload)->assertStatus(200);
        postSquareWebhook($payload)->assertStatus(200)->assertJson(['status' => 'duplicate']);

        expect(SquareWebhookEvent::where('square_event_id', $payload['event_id'])->count())->toBe(1);
        expect($this->product->fresh()->stock_quantity)->toBe(26);
    });

    it('adds stock back for a refund that restocks the item', function () {
        fakeSquareChangeLog([
            squareAdjustment('CHG_REFUND_1', 'SOLD', 'IN_STOCK', 2, ['refund_id' => 'REFUND_1']),
        ]);

        postSquareWebhook(squareInventoryPayload((string) Str::uuid(), [
            'data' => ['object' => ['inventory_counts' => [
                ['catalog_object_id' => 'SQ_ITEM_MAPPED', 'state' => 'IN_STOCK', 'quantity' => '32', 'location_id' => 'L_TEST'],
            ]]],
        ]))->assertStatus(200);

        expect($this->product->fresh()->stock_quantity)->toBe(32);
        expect(SquareInventoryChange::where('square_change_id', 'CHG_REFUND_1')->value('kind'))->toBe('restock');
    });

    it('ignores a recount made in Square\'s dashboard and pushes the local count over it', function () {
        Queue::fake([PushInventoryCountJob::class, PullSquareSalesJob::class]);

        // A PHYSICAL_COUNT never comes back from an ADJUSTMENT-only change
        // log query, so the change log has nothing to apply.
        fakeSquareChangeLog([]);

        postSquareWebhook(squareInventoryPayload((string) Str::uuid(), [
            'data' => ['object' => ['inventory_counts' => [
                ['catalog_object_id' => 'SQ_ITEM_MAPPED', 'state' => 'IN_STOCK', 'quantity' => '50', 'location_id' => 'L_TEST'],
            ]]],
        ]))->assertStatus(200);

        expect($this->product->fresh()->stock_quantity)->toBe(30);

        Queue::assertPushed(PushInventoryCountJob::class, fn (PushInventoryCountJob $job) => $job->productId === $this->product->id
            && $job->quantity === 30);

        test()->assertDatabaseHas('events', [
            'type' => 'square.manual_change_overridden',
            'subject_type' => Product::class,
            'subject_id' => $this->product->id,
            'severity' => 'warning',
        ]);
    });

    it('ignores waste/damage adjustments made on Square', function () {
        Queue::fake([PushInventoryCountJob::class, PullSquareSalesJob::class]);
        fakeSquareChangeLog([squareAdjustment('CHG_WASTE_1', 'IN_STOCK', 'WASTE', 5)]);

        postSquareWebhook(squareInventoryPayload((string) Str::uuid(), [
            'data' => ['object' => ['inventory_counts' => [
                ['catalog_object_id' => 'SQ_ITEM_MAPPED', 'state' => 'IN_STOCK', 'quantity' => '25', 'location_id' => 'L_TEST'],
            ]]],
        ]))->assertStatus(200);

        expect($this->product->fresh()->stock_quantity)->toBe(30);
        expect(SquareInventoryChange::count())->toBe(0);
        Queue::assertPushed(PushInventoryCountJob::class, fn (PushInventoryCountJob $job) => $job->quantity === 30);
    });

    it('does not push again when Square already matches local -- the loop terminates', function () {
        Queue::fake([PushInventoryCountJob::class, PullSquareSalesJob::class]);
        fakeSquareChangeLog([]);

        // What Square reports right after our own push landed.
        postSquareWebhook(squareInventoryPayload((string) Str::uuid(), [
            'data' => ['object' => ['inventory_counts' => [
                ['catalog_object_id' => 'SQ_ITEM_MAPPED', 'state' => 'IN_STOCK', 'quantity' => '30', 'location_id' => 'L_TEST'],
            ]]],
        ]))->assertStatus(200);

        Queue::assertNotPushed(PushInventoryCountJob::class);
        test()->assertDatabaseMissing('events', ['type' => 'square.manual_change_overridden']);
    });

    it('clamps at 0 and flags it when Square sold more than local stock', function () {
        $this->product->update(['stock_quantity' => 1]);
        fakeSquareChangeLog([squareAdjustment('CHG_OVERSOLD', 'IN_STOCK', 'SOLD', 3)]);

        postSquareWebhook(squareInventoryPayload((string) Str::uuid()))->assertStatus(200);

        expect($this->product->fresh()->stock_quantity)->toBe(0);
        test()->assertDatabaseHas('events', ['type' => 'square.sale_oversold', 'severity' => 'warning']);
    });

    it('reads the change log from the stored watermark, minus the overlap, and advances it', function () {
        (new UpdateSiteSetting)->handle(PullSquareInventoryChanges::WATERMARK_KEY, '2026-09-01T12:00:00+00:00');
        fakeSquareChangeLog([]);

        postSquareWebhook(squareInventoryPayload((string) Str::uuid()))->assertStatus(200);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v2/inventory/changes/batch-retrieve')
            && $request->data()['types'] === ['ADJUSTMENT']
            && $request->data()['location_ids'] === ['L_TEST']
            && $request->data()['updated_after'] === '2026-09-01T11:50:00+00:00');

        expect(app(GetSiteSetting::class)->handle(PullSquareInventoryChanges::WATERMARK_KEY))
            ->not->toBe('2026-09-01T12:00:00+00:00');
    });

    it('does not blow up on a change or count for an unmapped Square object', function () {
        fakeSquareChangeLog([
            squareAdjustment('CHG_UNMAPPED', 'IN_STOCK', 'SOLD', 1, ['catalog_object_id' => 'SQ_ITEM_NOBODY_MAPS_ME']),
        ]);

        $eventId = (string) Str::uuid();
        postSquareWebhook(squareInventoryPayload($eventId, [
            'data' => ['object' => ['inventory_counts' => [
                ['catalog_object_id' => 'SQ_ITEM_NOBODY_MAPS_ME', 'state' => 'IN_STOCK', 'quantity' => '9', 'location_id' => 'L_TEST'],
            ]]],
        ]))->assertStatus(200)->assertJson(['status' => 'success']);

        expect(SquareWebhookEvent::where('square_event_id', $eventId)->value('status'))->toBe('processed');
        expect($this->product->fresh()->stock_quantity)->toBe(30);
    });

    it('returns 500 so Square retries when the change log cannot be read', function () {
        Http::preventStrayRequests();
        Http::fake([
            'connect.squareupsandbox.com/v2/inventory/changes/batch-retrieve*' => Http::response(['errors' => []], 500),
        ]);

        $eventId = (string) Str::uuid();
        postSquareWebhook(squareInventoryPayload($eventId))->assertStatus(500);

        expect(SquareWebhookEvent::where('square_event_id', $eventId)->value('status'))->toBe('failed');
        expect($this->product->fresh()->stock_quantity)->toBe(30);
    });
});

/*
 * products.stock_quantity has no location dimension, so only the
 * configured location's movements and counts mean anything here.
 */
describe('location scoping', function () {
    beforeEach(function () {
        config([
            'square-sync.access_token' => 'sq0atp-test-token',
            'square-sync.location_id' => 'L_CONFIGURED',
            'square-sync.base_url' => 'https://connect.squareupsandbox.com',
            'square-sync.version' => '2025-01-23',
        ]);

        Queue::fake([PullSquareSalesJob::class]);

        $this->product = Product::factory()->create(['stock_quantity' => 30, 'track_inventory' => true]);
        SquareObjectMapping::linkTo($this->product->id, 'SQ_ITEM_MAPPED', 'ITEM_VARIATION');
    });

    it('ignores sales and counts from a location other than the configured one', function () {
        Queue::fake([PushInventoryCountJob::class, PullSquareSalesJob::class]);
        fakeSquareChangeLog([squareAdjustment('CHG_OTHER_LOC', 'IN_STOCK', 'SOLD', 2, ['location_id' => 'L_OTHER'])]);

        postSquareWebhook(squareInventoryPayload((string) Str::uuid(), [
            'data' => ['object' => ['inventory_counts' => [
                ['catalog_object_id' => 'SQ_ITEM_MAPPED', 'state' => 'IN_STOCK', 'quantity' => '3', 'location_id' => 'L_OTHER'],
            ]]],
        ]))->assertStatus(200);

        expect($this->product->fresh()->stock_quantity)->toBe(30);
        Queue::assertNotPushed(PushInventoryCountJob::class);
    });

    it('does nothing at all without a configured location', function () {
        config(['square-sync.location_id' => null]);
        Http::fake();

        postSquareWebhook(squareInventoryPayload((string) Str::uuid()))->assertStatus(200);

        Http::assertNothingSent();
        expect($this->product->fresh()->stock_quantity)->toBe(30);
    });
});

describe('unknown event types', function () {
    it('returns 200 and marks the square_webhook_events row skipped', function () {
        $eventId = (string) Str::uuid();
        $payload = ['event_id' => $eventId, 'type' => 'payment.updated'];

        postSquareWebhook($payload)->assertStatus(200)->assertJson(['status' => 'skipped']);

        $webhookEvent = SquareWebhookEvent::where('square_event_id', $eventId)->first();

        expect($webhookEvent)->not->toBeNull()
            ->and($webhookEvent->status)->toBe('skipped');
    });
});

describe('catalog.version.updated', function () {
    beforeEach(function () {
        config([
            'square-sync.access_token' => 'sq0atp-test-token',
            'square-sync.location_id' => 'L_TEST_LOCATION',
            'square-sync.base_url' => 'https://connect.squareupsandbox.com',
            'square-sync.version' => '2025-01-23',
            'square-sync.timeout' => 15,
            'square-sync.retry_times' => 2,
            'square-sync.retry_sleep_ms' => 5,
        ]);
        Sleep::fake();
    });

    it('returns 200 and runs a catalog delta pull', function () {
        Http::fake([
            'connect.squareupsandbox.com/v2/catalog/search-catalog-objects*' => Http::response([
                'objects' => [],
            ], 200),
        ]);

        $eventId = (string) Str::uuid();
        $payload = ['event_id' => $eventId, 'type' => 'catalog.version.updated'];

        postSquareWebhook($payload)->assertStatus(200)->assertJson(['status' => 'success']);

        $webhookEvent = SquareWebhookEvent::where('square_event_id', $eventId)->first();
        expect($webhookEvent->status)->toBe('processed');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/catalog/search-catalog-objects'));
    });

    it('returns 500 and marks the webhook event failed when the delta pull errors', function () {
        Http::fake([
            'connect.squareupsandbox.com/v2/catalog/search-catalog-objects*' => Http::response([
                'errors' => [['category' => 'API_ERROR', 'code' => 'INTERNAL_SERVER_ERROR', 'detail' => 'Boom']],
            ], 500),
        ]);

        $eventId = (string) Str::uuid();
        $payload = ['event_id' => $eventId, 'type' => 'catalog.version.updated'];

        postSquareWebhook($payload)->assertStatus(500);

        $webhookEvent = SquareWebhookEvent::where('square_event_id', $eventId)->first();
        expect($webhookEvent->status)->toBe('failed');
    });
});

describe('audit trail', function () {
    it('writes a webhook.received event correlated to the Square event id', function () {
        $eventId = (string) Str::uuid();
        $payload = ['event_id' => $eventId, 'type' => 'payment.updated'];

        postSquareWebhook($payload)->assertStatus(200);

        test()->assertDatabaseHas('events', [
            'type' => 'webhook.received',
            'correlation_id' => $eventId,
        ]);
    });
});

describe('square:reconcile', function () {
    it('reports drift with --dry-run without mutating stock', function () {
        config([
            'square-sync.access_token' => 'sq0atp-test-token',
            'square-sync.location_id' => 'L_TEST_LOCATION',
            'square-sync.base_url' => 'https://connect.squareupsandbox.com',
            'square-sync.version' => '2025-01-23',
            'square-sync.timeout' => 15,
            'square-sync.retry_times' => 3,
            'square-sync.retry_sleep_ms' => 5,
        ]);

        $product = Product::factory()->create(['stock_quantity' => 5, 'track_inventory' => true]);
        SquareObjectMapping::factory()->create([
            'mappable_type' => Product::class,
            'mappable_id' => $product->id,
            'square_object_id' => 'SQ_ITEM_DRIFTED',
            'sync_status' => 'linked',
        ]);

        Http::fake([
            'connect.squareupsandbox.com/v2/inventory/counts/batch-retrieve*' => Http::response([
                'counts' => [
                    [
                        'catalog_object_id' => 'SQ_ITEM_DRIFTED',
                        'state' => 'IN_STOCK',
                        'quantity' => '99',
                        'location_id' => 'L_TEST_LOCATION',
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('square:reconcile', ['--dry-run' => true])
            ->expectsOutputToContain('1 product(s) drifted from Square.')
            ->assertExitCode(0);

        expect($product->fresh()->stock_quantity)->toBe(5);

        test()->assertDatabaseHas('events', [
            'type' => 'square.drift_detected',
        ]);
    });
});
