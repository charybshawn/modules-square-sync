<?php

use App\Actions\GetSiteSetting;
use App\Actions\UpdateSiteSetting;
use App\Models\Product;
use App\Models\User;
use Cultpantry\SquareSync\Actions\GetSquareLocationId;
use Cultpantry\SquareSync\Actions\PullSquareCatalogDelta;
use Cultpantry\SquareSync\Actions\PullSquareInventoryChanges;
use Cultpantry\SquareSync\Actions\PullSquareSales;
use Cultpantry\SquareSync\Actions\QueueStockPush;
use Cultpantry\SquareSync\Actions\SquareAccount;
use Cultpantry\SquareSync\Jobs\PushInventoryCountJob;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Square\SquareClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * Sandbox and production are separate Square accounts, so switching
 * SQUARE_ENVIRONMENT resets everything the module stored against the
 * old one: links, the in-app location, and change-log watermarks. (A
 * different seller within one environment is HealthCheckTest's.)
 */

beforeEach(function () {
    config([
        'square-sync.access_token' => 'sq0atp-test-token',
        'square-sync.environment' => 'sandbox',
        'square-sync.location_id' => null,
        'square-sync.base_url' => 'https://connect.squareupsandbox.com',
    ]);

    $this->settings = app(UpdateSiteSetting::class);
    $this->setting = fn (string $key) => app(GetSiteSetting::class)->handle($key);

    $this->product = Product::factory()->create(['stock_quantity' => 10, 'track_inventory' => true]);
    SquareObjectMapping::linkTo($this->product->id, 'SQ_SANDBOX_VAR', 'ITEM_VARIATION');
    $this->settings->handle(GetSquareLocationId::SETTING_KEY, 'L_SANDBOX');
    foreach ([PullSquareCatalogDelta::WATERMARK_KEY, PullSquareInventoryChanges::WATERMARK_KEY, PullSquareSales::WATERMARK_KEY] as $key) {
        $this->settings->handle($key, '2026-01-01T00:00:00+00:00');
    }
});

it('just remembers the environment the first time, keeping existing links', function () {
    expect(app(SquareAccount::class)->guard())->toBeFalse()
        ->and(($this->setting)(SquareAccount::ENVIRONMENT_KEY))->toBe('sandbox')
        ->and(SquareObjectMapping::count())->toBe(1);
});

it('does nothing while the environment stays the same', function () {
    app(SquareAccount::class)->guard();

    expect(app(SquareAccount::class)->guard())->toBeFalse()
        ->and(SquareObjectMapping::count())->toBe(1)
        ->and(($this->setting)(GetSquareLocationId::SETTING_KEY))->toBe('L_SANDBOX');
});

it('unlinks everything, clears the location and restarts the watermarks after a switch', function () {
    app(SquareAccount::class)->guard();
    config(['square-sync.environment' => 'production']);

    expect(app(SquareAccount::class)->guard())->toBeTrue()
        ->and(app(SquareAccount::class)->guard())->toBeFalse();

    expect(SquareObjectMapping::count())->toBe(0)
        // Soft-deleted: the old environment's sync history survives.
        ->and(SquareObjectMapping::withTrashed()->count())->toBe(1)
        ->and(app(GetSquareLocationId::class)->handle())->toBeNull()
        ->and(($this->setting)(SquareAccount::ENVIRONMENT_KEY))->toBe('production');

    foreach ([PullSquareCatalogDelta::WATERMARK_KEY, PullSquareInventoryChanges::WATERMARK_KEY, PullSquareSales::WATERMARK_KEY] as $key) {
        expect(($this->setting)($key))->not->toBe('2026-01-01T00:00:00+00:00');
    }

    $this->assertDatabaseHas('events', ['type' => 'square.account_changed', 'severity' => 'warning']);
});

it('never queues a push against a link from the old environment', function () {
    app(SquareAccount::class)->guard();
    config(['square-sync.environment' => 'production', 'square-sync.location_id' => 'L_PROD']);
    Queue::fake([PushInventoryCountJob::class]);

    app(QueueStockPush::class)->handle($this->product->id, 7);

    Queue::assertNotPushed(PushInventoryCountJob::class);
});

it('resets before any Square API call goes out', function () {
    app(SquareAccount::class)->guard();
    config(['square-sync.environment' => 'production']);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['locations' => []], 200)]);

    app(SquareClient::class)->locations()->list();

    expect(SquareObjectMapping::count())->toBe(0);
});

it('shows no stale links on the admin page after a switch', function () {
    app(SquareAccount::class)->guard();
    config(['square-sync.environment' => 'production', 'square-sync.access_token' => null]);

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('admin.square.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('mappings.data', 0));
});
