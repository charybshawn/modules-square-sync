<?php

use App\Models\Event;
use Cultpantry\SquareSync\Square\InventoryApi;
use Cultpantry\SquareSync\Square\SquareClient;
use Cultpantry\SquareSync\Square\SquareException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

// Establishes the Http::fake() pattern for the whole app -- this is the
// first module to use it, so every assertion here is written to be the
// copyable reference for later modules rather than a one-off.
beforeEach(function () {
    config([
        'square-sync.access_token' => 'sq0atp-super-secret-token-value',
        'square-sync.location_id' => 'L_TEST_LOCATION',
        'square-sync.base_url' => 'https://connect.squareupsandbox.com',
        'square-sync.version' => '2025-01-23',
        'square-sync.timeout' => 15,
        'square-sync.retry_times' => 3,
        'square-sync.retry_sleep_ms' => 5,
    ]);

    // Retries genuinely sleep otherwise (Sleep::usleep under the hood) --
    // faking it keeps the 429-then-success and exhausted-retries tests fast.
    Sleep::fake();
});

it('sends the correct method, url, Square-Version header, and bearer token', function () {
    Http::fake([
        '*' => Http::response(['locations' => []], 200),
    ]);

    app(SquareClient::class)->request('GET', '/v2/locations');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://connect.squareupsandbox.com/v2/locations'
            && $request->method() === 'GET'
            && $request->hasHeader('Square-Version', '2025-01-23')
            && $request->hasHeader('Authorization', 'Bearer sq0atp-super-secret-token-value');
    });
});

it('paginates via cursor across multiple pages and stops when no cursor is returned', function () {
    Http::fake([
        'connect.squareupsandbox.com/v2/catalog/list*' => Http::sequence()
            ->push(['objects' => [['id' => 'A']], 'cursor' => 'page-2'], 200)
            ->push(['objects' => [['id' => 'B']], 'cursor' => 'page-3'], 200)
            ->push(['objects' => [['id' => 'C']]], 200), // no cursor in the body -> pagination stops
    ]);

    $items = app(SquareClient::class)->catalog()->listItems()->all();

    expect(array_column($items, 'id'))->toBe(['A', 'B', 'C']);

    Http::assertSentCount(3);
});

it('retries once on a 429 and succeeds on the following attempt', function () {
    Http::fake([
        'connect.squareupsandbox.com/v2/locations*' => Http::sequence()
            ->push(['errors' => [['category' => 'RATE_LIMIT_ERROR', 'code' => 'RATE_LIMITED', 'detail' => 'Slow down']]], 429)
            ->push(['locations' => [['id' => 'L1']]], 200),
    ]);

    $response = app(SquareClient::class)->locations()->list();

    expect($response->ok())->toBeTrue()
        ->and($response->json('locations.0.id'))->toBe('L1');

    Http::assertSentCount(2);
});

it('throws SquareException carrying the status and error payload after a persistent 500 exhausts retries', function () {
    config(['square-sync.retry_times' => 2]);

    Http::fake([
        'connect.squareupsandbox.com/v2/locations*' => Http::response(
            ['errors' => [['category' => 'API_ERROR', 'code' => 'INTERNAL_SERVER_ERROR', 'detail' => 'Boom']]],
            500
        ),
    ]);

    try {
        app(SquareClient::class)->locations()->list();

        $this->fail('Expected SquareException to be thrown.');
    } catch (SquareException $exception) {
        expect($exception->httpStatus())->toBe(500)
            ->and($exception->errors())->toBe([[
                'category' => 'API_ERROR',
                'code' => 'INTERNAL_SERVER_ERROR',
                'detail' => 'Boom',
            ]]);
    }

    // retry_times = 2 means two total attempts, not two retries on top of a first try.
    Http::assertSentCount(2);
});

it('writes a correlated square.request/square.response pair to the events table', function () {
    Http::fake([
        'connect.squareupsandbox.com/v2/locations*' => Http::response(['locations' => []], 200),
    ]);

    app(SquareClient::class)->request('GET', '/v2/locations', [], 'sync-run-123');

    $this->assertDatabaseHas('events', [
        'type' => 'square.request',
        'direction' => 'outbound',
        'correlation_id' => 'sync-run-123',
    ]);

    $this->assertDatabaseHas('events', [
        'type' => 'square.response',
        'direction' => 'inbound',
        'correlation_id' => 'sync-run-123',
    ]);
});

it('falls through to the ambient trace id when no correlation id is passed', function () {
    Context::add('trace_id', 'ambient-trace-abc');

    Http::fake([
        'connect.squareupsandbox.com/v2/locations*' => Http::response(['locations' => []], 200),
    ]);

    app(SquareClient::class)->request('GET', '/v2/locations');

    $this->assertDatabaseHas('events', [
        'type' => 'square.request',
        'correlation_id' => 'ambient-trace-abc',
    ]);
});

it('mints a fresh correlation id when neither an explicit one nor an ambient trace exists', function () {
    Http::fake([
        'connect.squareupsandbox.com/v2/locations*' => Http::response(['locations' => []], 200),
    ]);

    app(SquareClient::class)->request('GET', '/v2/locations');

    $event = Event::where('type', 'square.request')->latest('id')->first();
    expect($event->correlation_id)->not->toBeNull();
});

it('records a square.error event with severity error when the request ultimately fails', function () {
    config(['square-sync.retry_times' => 1]);

    Http::fake([
        'connect.squareupsandbox.com/v2/locations*' => Http::response(
            ['errors' => [['category' => 'AUTHENTICATION_ERROR', 'code' => 'UNAUTHORIZED', 'detail' => 'Bad token']]],
            401
        ),
    ]);

    try {
        app(SquareClient::class)->request('GET', '/v2/locations', [], 'sync-run-error');
    } catch (SquareException) {
        // asserted on below via the events table
    }

    $this->assertDatabaseHas('events', [
        'type' => 'square.error',
        'direction' => 'inbound',
        'severity' => 'error',
        'correlation_id' => 'sync-run-error',
    ]);
});

it('never logs the access token in any events row, across request, response, and error events', function () {
    Http::fake([
        'connect.squareupsandbox.com/v2/catalog/*' => Http::sequence()
            ->push(['objects' => []], 200) // batchUpsert succeeds
            ->push(['errors' => [['category' => 'NOT_FOUND', 'code' => 'NOT_FOUND', 'detail' => 'missing']]], 404), // deleteObject fails, no retry
    ]);

    app(SquareClient::class)->catalog()->batchUpsert([
        ['type' => 'ITEM', 'id' => '#temp'],
    ], 'idem-key-1');

    try {
        app(SquareClient::class)->catalog()->deleteObject('some-id');
    } catch (SquareException) {
        // expected -- we only care that nothing logged the token
    }

    $token = config('square-sync.access_token');
    $rows = Event::query()->get(['metadata']);

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect(json_encode($row->metadata))->not->toContain($token);
    }
});

it('chunks batchChangeInventory at 100 changes per request', function () {
    Http::fake([
        'connect.squareupsandbox.com/v2/inventory/changes/batch-create*' => Http::response(['counts' => []], 200),
    ]);

    $changes = collect(range(1, 250))
        ->map(fn (int $i) => InventoryApi::physicalCount("item-{$i}", 5, 'L_TEST_LOCATION'))
        ->all();

    app(SquareClient::class)->inventory()->batchChangeInventory($changes, 'idem-root');

    Http::assertSentCount(3);

    $chunkSizes = collect(Http::recorded())
        ->map(fn (array $pair) => count($pair[0]->data()['changes'] ?? []))
        ->all();

    expect($chunkSizes)->toBe([100, 100, 50]);
});

it('builds a PHYSICAL_COUNT change with quantity as a string', function () {
    $change = InventoryApi::physicalCount('item-1', 42, 'L_TEST_LOCATION');

    expect($change['type'])->toBe('PHYSICAL_COUNT')
        ->and($change['physical_count']['quantity'])->toBe('42')
        ->and($change['physical_count']['quantity'])->toBeString()
        ->and($change['physical_count']['location_id'])->toBe('L_TEST_LOCATION')
        ->and($change['physical_count']['state'])->toBe('IN_STOCK');
});
