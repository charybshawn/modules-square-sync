<?php

use App\Actions\UpdateSiteSetting;
use App\Models\Product;
use App\Models\User;
use App\Notifications\SquareSyncAlert;
use Cultpantry\SquareSync\Actions\CheckSquareHealth;
use Cultpantry\SquareSync\Actions\GetSquareLocationId;
use Cultpantry\SquareSync\Actions\SquareAccount;
use Cultpantry\SquareSync\Contracts\AdminAlerts;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * CheckSquareHealth: the live answer to "is the Square sync working?" --
 * token, account, permissions, location, links -- and the alerts when
 * that answer changes.
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

    Http::preventStrayRequests();

    // Alerts go out after the response; run them inline here.
    $this->withoutDefer();

    // Records what would be sent to admins.
    $this->alerts = new class implements AdminAlerts
    {
        public array $sent = [];

        public function send(string $title, array $lines, string $level, string $url): void
        {
            $this->sent[] = compact('title', 'lines', 'level');
        }
    };
    app()->instance(AdminAlerts::class, $this->alerts);

    $this->product = Product::factory()->create(['stock_quantity' => 10, 'track_inventory' => true]);
    $this->mapping = SquareObjectMapping::linkTo($this->product->id, 'VAR_1', 'ITEM_VARIATION');
});

// Http::fake() stubs accumulate and the first match wins, so a test
// that changes what Square says starts from a fresh fake.
function resetSquareFake(): void
{
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
}

function fakeHealthyCatalog(string $merchantId = 'MERCHANT_A'): void
{
    resetSquareFake();
    Http::fake([
        ...squareHealthFakes($merchantId),
        'connect.squareupsandbox.com/v2/catalog/batch-retrieve*' => Http::response([
            'objects' => [['type' => 'ITEM_VARIATION', 'id' => 'VAR_1', 'item_variation_data' => ['item_id' => 'ITEM_1']]],
            'related_objects' => [['type' => 'ITEM', 'id' => 'ITEM_1', 'item_data' => ['name' => 'Pea Shoots']]],
        ], 200),
    ]);
}

it('reports online, verifies every link, and stores the snapshot', function () {
    fakeHealthyCatalog();

    $health = app(CheckSquareHealth::class)->handle();

    expect($health['status'])->toBe('online')
        ->and($health['merchant_id'])->toBe('MERCHANT_A')
        ->and($health['problems'])->toBe([])
        ->and($health['location']['name'])->toBe('Market Stall')
        ->and($health['links'])->toMatchArray(['checked' => 1, 'ok' => 1])
        ->and($this->mapping->fresh()->verification_status)->toBe('ok')
        ->and(app(CheckSquareHealth::class)->snapshot()['status'])->toBe('online');
});

it('goes offline on a rejected token without judging the links', function () {
    Http::fake([
        'connect.squareupsandbox.com/oauth2/token/status' => Http::response(['errors' => [['code' => 'UNAUTHORIZED']]], 401),
    ]);

    $health = app(CheckSquareHealth::class)->handle();

    expect($health['status'])->toBe('offline')
        ->and($health['problems'][0]['key'])->toBe('token_invalid')
        ->and($health['links'])->toBeNull()
        // A bad token isn't evidence the item is gone.
        ->and($this->mapping->fresh()->sync_status)->toBe('linked');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'batch-retrieve'));
});

it('is not configured without a token, and never calls Square', function () {
    config(['square-sync.access_token' => null]);

    $health = app(CheckSquareHealth::class)->handle();

    expect($health['status'])->toBe('not_configured');
    Http::assertNothingSent();
});

it('goes offline when the token is missing permissions', function () {
    Http::fake([...squareHealthFakes(scopes: ['ITEMS_READ']), 'connect.squareupsandbox.com/v2/catalog/*' => Http::response(['objects' => []], 200)]);

    $health = app(CheckSquareHealth::class)->handle();

    expect($health['status'])->toBe('offline')
        ->and($health['problems'][0]['key'])->toBe('scopes_missing')
        ->and($health['problems'][0]['message'])->toContain('INVENTORY_WRITE');
});

it('goes offline when the sync location is gone or inactive', function (int $status, array $body, string $key) {
    Http::fake([
        'connect.squareupsandbox.com/oauth2/token/status' => Http::response(['merchant_id' => 'MERCHANT_A', 'scopes' => CheckSquareHealth::REQUIRED_SCOPES], 200),
        'connect.squareupsandbox.com/v2/locations/*' => Http::response($body, $status),
    ]);

    $health = app(CheckSquareHealth::class)->handle();

    expect($health['status'])->toBe('offline')
        ->and($health['problems'][0]['key'])->toBe($key);
})->with([
    'not on this account' => [404, ['errors' => [['code' => 'NOT_FOUND']]], 'location_not_found'],
    'inactive' => [200, ['location' => ['id' => 'L_SYNC', 'name' => 'Old Stall', 'status' => 'INACTIVE']], 'location_inactive'],
]);

it('adopts the first merchant it sees, then resets the links when the token belongs to another', function () {
    fakeHealthyCatalog('MERCHANT_A');
    app(CheckSquareHealth::class)->handle();

    expect(app(SquareAccount::class)->merchantId())->toBe('MERCHANT_A')
        ->and(SquareObjectMapping::count())->toBe(1);

    app(UpdateSiteSetting::class)->handle(GetSquareLocationId::SETTING_KEY, 'L_OLD_ACCOUNT');
    fakeHealthyCatalog('MERCHANT_B');
    $health = app(CheckSquareHealth::class)->handle();

    expect(SquareObjectMapping::count())->toBe(0)
        ->and(SquareObjectMapping::withTrashed()->count())->toBe(1)
        ->and(app(SquareAccount::class)->merchantId())->toBe('MERCHANT_B')
        ->and(collect($health['warnings'])->pluck('key'))->toContain('account_changed')
        ->and($this->alerts->sent)->toHaveCount(1)
        ->and($this->alerts->sent[0]['lines'][0])->toContain('different Square account');

    $this->assertDatabaseHas('events', ['type' => 'square.account_changed', 'severity' => 'warning']);
});

it('alerts once when something breaks, stays quiet while it stays broken, and alerts on recovery', function () {
    fakeHealthyCatalog();
    app(CheckSquareHealth::class)->handle();
    expect($this->alerts->sent)->toBe([]);

    // The linked item disappears from Square.
    resetSquareFake();
    Http::fake([...squareHealthFakes(), 'connect.squareupsandbox.com/v2/catalog/batch-retrieve*' => Http::response(['objects' => []], 200)]);
    app(CheckSquareHealth::class)->handle();
    app(CheckSquareHealth::class)->handle();

    expect($this->alerts->sent)->toHaveCount(1)
        ->and($this->alerts->sent[0]['level'])->toBe('warning')
        ->and($this->alerts->sent[0]['lines'][0])->toContain($this->product->title)
        ->and($this->mapping->fresh()->sync_status)->toBe('orphaned');

    fakeHealthyCatalog();
    app(CheckSquareHealth::class)->handle();

    expect($this->alerts->sent)->toHaveCount(2)
        ->and($this->alerts->sent[1]['title'])->toBe('Square sync is healthy again')
        ->and($this->mapping->fresh()->sync_status)->toBe('linked');

    $this->assertDatabaseHas('events', ['type' => 'square.health_degraded']);
    $this->assertDatabaseHas('events', ['type' => 'square.health_restored']);
});

it('emails every admin through the host\'s AdminAlerts binding', function () {
    Notification::fake();
    app()->forgetInstance(AdminAlerts::class);
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = User::factory()->create(['role' => 'customer']);

    Http::fake(['connect.squareupsandbox.com/oauth2/token/status' => Http::response([], 401)]);
    app(CheckSquareHealth::class)->handle();

    Notification::assertSentTo($admin, SquareSyncAlert::class, fn (SquareSyncAlert $alert) => $alert->title === 'Square sync is offline');
    Notification::assertNotSentTo($customer, SquareSyncAlert::class);
});

it('still answers when the alert can\'t be sent', function () {
    app()->instance(AdminAlerts::class, new class implements AdminAlerts
    {
        public function send(string $title, array $lines, string $level, string $url): void
        {
            throw new RuntimeException('Connection could not be established with the mail server');
        }
    });
    Http::fake(['connect.squareupsandbox.com/oauth2/token/status' => Http::response([], 401)]);

    $health = app(CheckSquareHealth::class)->handle();

    expect($health['status'])->toBe('offline')
        ->and(app(CheckSquareHealth::class)->snapshot()['status'])->toBe('offline');
    $this->assertDatabaseHas('events', ['type' => 'square.health_degraded']);
});

it('square:check fails the scheduled run while the connection is down', function () {
    Http::fake(['connect.squareupsandbox.com/oauth2/token/status' => Http::response([], 401)]);

    $this->artisan('square:check')
        ->expectsOutputToContain('Square connection: OFFLINE')
        ->assertFailed();
});

describe('admin page', function () {
    beforeEach(function () {
        $this->admin = User::factory()->create(['role' => 'admin']);
    });

    it('runs the live check from the health endpoint', function () {
        fakeHealthyCatalog();

        $this->actingAs($this->admin)
            ->postJson(route('admin.square.health'))
            ->assertOk()
            ->assertJson(['status' => 'online', 'links' => ['checked' => 1, 'ok' => 1]]);
    });

    it('renders the last snapshot, flagged stale when the schedule stopped', function () {
        fakeHealthyCatalog();
        $this->travel(-2)->hours();
        app(CheckSquareHealth::class)->handle();
        $this->travelBack();

        // The page render goes through Inertia's SSR server.
        Http::allowStrayRequests();

        $this->actingAs($this->admin)
            ->get(route('admin.square.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('health.status', 'online')
                ->where('health.stale', true)
                ->where('testSale', null));
    });

    it('keeps the health, diagnostics and test sale endpoints admin-only', function () {
        $customer = User::factory()->create(['role' => 'customer']);

        // With APP_DEBUG off (as in CI) the 403 page renders through
        // Inertia's SSR server.
        Http::allowStrayRequests();

        foreach (['admin.square.health', 'admin.square.diagnostics', 'admin.square.test-sale.start'] as $name) {
            $this->actingAs($customer)->postJson(route($name))->assertForbidden();
        }
    });
});
