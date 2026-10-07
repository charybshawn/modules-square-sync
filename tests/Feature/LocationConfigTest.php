<?php

use App\Actions\UpdateSiteSetting;
use App\Models\Event;
use App\Models\User;
use Cultpantry\SquareSync\Actions\FetchSquareLocations;
use Cultpantry\SquareSync\Actions\GetSquareLocationId;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * Covers making the Square location id configurable from the admin UI
 * instead of only SQUARE_LOCATION_ID in .env: the GetSquareLocationId
 * resolver's precedence, FetchSquareLocations's Square-down resilience,
 * and the admin.square.location endpoint that lets an admin pick one.
 */

describe('GetSquareLocationId resolver', function () {
    it('prefers the site setting over the env/config fallback', function () {
        config(['square-sync.location_id' => 'ENV_LOCATION']);
        (new UpdateSiteSetting)->handle(GetSquareLocationId::SETTING_KEY, 'SETTING_LOCATION');

        expect(app(GetSquareLocationId::class)->handle())->toBe('SETTING_LOCATION');
        expect(app(GetSquareLocationId::class)->source())->toBe('setting');
    });

    it('falls back to config/env when no setting is stored', function () {
        config(['square-sync.location_id' => 'ENV_LOCATION']);

        expect(app(GetSquareLocationId::class)->handle())->toBe('ENV_LOCATION');
        expect(app(GetSquareLocationId::class)->source())->toBe('env');
    });

    it('returns null and a null source when neither is set', function () {
        config(['square-sync.location_id' => null]);

        expect(app(GetSquareLocationId::class)->handle())->toBeNull();
        expect(app(GetSquareLocationId::class)->source())->toBeNull();
    });
});

describe('FetchSquareLocations', function () {
    it('returns an empty array with no token and never issues an HTTP call', function () {
        config(['square-sync.access_token' => null]);
        Http::fake();

        expect(app(FetchSquareLocations::class)->handle())->toBe([]);

        Http::assertNothingSent();
    });

    it('maps Square\'s payload to the shape the admin UI expects', function () {
        config(['square-sync.access_token' => 'test-token']);
        Http::fake([
            'connect.squareupsandbox.com/*' => Http::response(['locations' => [
                [
                    'id' => 'L_MAIN',
                    'name' => 'Main Street Bakery',
                    'status' => 'ACTIVE',
                    'address' => [
                        'address_line_1' => '123 Main St',
                        'locality' => 'Calgary',
                        'administrative_district_level_1' => 'AB',
                    ],
                ],
                [
                    'id' => 'L_OLD',
                    'name' => 'Closed Kiosk',
                    'status' => 'INACTIVE',
                ],
            ]], 200),
        ]);

        $locations = app(FetchSquareLocations::class)->handle();

        expect($locations)->toBe([
            [
                'id' => 'L_MAIN',
                'name' => 'Main Street Bakery',
                'status' => 'ACTIVE',
                'address' => '123 Main St, Calgary, AB',
            ],
            [
                'id' => 'L_OLD',
                'name' => 'Closed Kiosk',
                'status' => 'INACTIVE',
                'address' => null,
            ],
        ]);
    });

    it('returns an empty array and does not throw when Square returns a 500', function () {
        config(['square-sync.access_token' => 'test-token', 'square-sync.retry_times' => 1]);

        // Retries genuinely sleep otherwise -- see SquareClientTest, whose
        // Http::fake()/Sleep::fake() pattern this mirrors.
        Sleep::fake();

        Http::fake([
            'connect.squareupsandbox.com/*' => Http::response(['errors' => [['detail' => 'boom']]], 500),
        ]);

        expect(app(FetchSquareLocations::class)->handle())->toBe([]);
    });

    it('caches the result for 5 minutes so the admin page does not hit Square on every load', function () {
        config(['square-sync.access_token' => 'test-token']);
        Http::fake([
            'connect.squareupsandbox.com/*' => Http::response(['locations' => [['id' => 'L1', 'name' => 'One', 'status' => 'ACTIVE']]], 200),
        ]);

        app(FetchSquareLocations::class)->handle();
        app(FetchSquareLocations::class)->handle();

        Http::assertSentCount(1);
    });
});

describe('admin.square.location endpoint', function () {
    beforeEach(function () {
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->customer = User::factory()->create(['role' => 'customer']);

        config(['square-sync.access_token' => 'test-token']);
    });

    it('saves the setting and the resolver reflects it immediately', function () {
        // Starts on the env fallback so the audit row's previous_value has
        // something real to capture, mirroring a first-time switch away
        // from a SQUARE_LOCATION_ID that was set before the picker existed.
        config(['square-sync.location_id' => 'L_ENV_FALLBACK']);

        Http::fake([
            'connect.squareupsandbox.com/*' => Http::response(['locations' => [
                ['id' => 'L_NEW', 'name' => 'New Location', 'status' => 'ACTIVE'],
            ]], 200),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.square.location'), ['location_id' => 'L_NEW'])
            ->assertRedirect();

        // GetSiteSetting caches for 24h -- UpdateSiteSetting invalidates
        // that cache key on write, so this must reflect the new value
        // without a manual Cache::flush() if the invalidation is correct.
        expect(app(GetSquareLocationId::class)->handle())->toBe('L_NEW');
        expect(app(GetSquareLocationId::class)->source())->toBe('setting');

        // Both values recorded, so the audit row alone explains a later
        // divergence without cross-referencing settings history.
        $event = Event::where('type', 'square.location_changed')->firstOrFail();

        expect($event->metadata['previous_value'])->toBe('L_ENV_FALLBACK')
            ->and($event->metadata['new_value'])->toBe('L_NEW');
    });

    it('rejects an id that is not in the Square list', function () {
        Http::fake([
            'connect.squareupsandbox.com/*' => Http::response(['locations' => [
                ['id' => 'L_REAL', 'name' => 'Real Location', 'status' => 'ACTIVE'],
            ]], 200),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.square.location'), ['location_id' => 'L_MADE_UP'])
            ->assertSessionHasErrors('location_id');

        expect(app(GetSquareLocationId::class)->handle())->not->toBe('L_MADE_UP');
    });

    it('rejects any id when the Square list is empty (token missing/Square down)', function () {
        config(['square-sync.access_token' => null]);
        Http::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.square.location'), ['location_id' => 'L_ANY'])
            ->assertSessionHasErrors('location_id');
    });

    it('is admin-gated: customer forbidden, guest redirected, admin allowed', function () {
        Http::fake([
            'connect.squareupsandbox.com/*' => Http::response(['locations' => [
                ['id' => 'L_NEW', 'name' => 'New Location', 'status' => 'ACTIVE'],
            ]], 200),
        ]);

        $this->post(route('admin.square.location'), ['location_id' => 'L_NEW'])
            ->assertRedirect(route('login'));

        $this->actingAs($this->customer)
            ->post(route('admin.square.location'), ['location_id' => 'L_NEW'])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->post(route('admin.square.location'), ['location_id' => 'L_NEW'])
            ->assertRedirect();
    });

    it('exposes the saved location and its source to the admin page props', function () {
        Http::fake([
            'connect.squareupsandbox.com/*' => Http::response(['locations' => [
                ['id' => 'L_NEW', 'name' => 'New Location', 'status' => 'ACTIVE'],
            ]], 200),
        ]);

        (new UpdateSiteSetting)->handle(GetSquareLocationId::SETTING_KEY, 'L_NEW');
        Cache::flush();

        $this->actingAs($this->admin)
            ->get(route('admin.square.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('connection.selected_location_id', 'L_NEW')
                ->where('connection.location_source', 'setting')
                ->has('connection.locations', 1)
            );
    });
});
