<?php

use App\Models\Product;
use App\Models\User;
use Cultpantry\SquareSync\Actions\QueueStockPush;
use Cultpantry\SquareSync\Actions\VerifySquareLinks;
use Cultpantry\SquareSync\Jobs\PushCatalogObjectJob;
use Cultpantry\SquareSync\Jobs\PushInventoryCountJob;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * VerifySquareLinks checks every product link against the connected
 * Square account and flags -- never unlinks -- the ones that went stale.
 */

beforeEach(function () {
    config([
        'square-sync.access_token' => 'sq0atp-test-token',
        'square-sync.location_id' => 'L_SYNC',
        'square-sync.base_url' => 'https://connect.squareupsandbox.com',
        'square-sync.version' => '2025-01-23',
        'square-sync.retry_times' => 1,
    ]);

    Http::preventStrayRequests();
});

// Link verification only ever runs inside the health check, after the
// token and location are confirmed.

function linkProduct(string $squareId): array
{
    $product = Product::factory()->create(['stock_quantity' => 10, 'track_inventory' => true]);

    return [$product, SquareObjectMapping::linkTo($product->id, $squareId, 'ITEM_VARIATION')];
}

function variation(string $id, string $itemId, array $extra = []): array
{
    return ['type' => 'ITEM_VARIATION', 'id' => $id, 'item_variation_data' => ['item_id' => $itemId], ...$extra];
}

function item(string $id, array $itemData = [], array $extra = []): array
{
    return ['type' => 'ITEM', 'id' => $id, 'item_data' => ['name' => $id, ...$itemData], ...$extra];
}

function fakeCatalog(array $objects, array $related = []): void
{
    Http::fake([
        ...squareHealthFakes(),
        'connect.squareupsandbox.com/v2/catalog/batch-retrieve*' => Http::response([
            'objects' => $objects,
            'related_objects' => $related,
        ], 200),
    ]);
}

it('classifies each link: ok, missing, archived, not at the sync location', function () {
    [, $ok] = linkProduct('VAR_OK');
    [, $missing] = linkProduct('VAR_GONE');
    [, $archived] = linkProduct('VAR_ARCHIVED');
    [, $elsewhere] = linkProduct('VAR_ELSEWHERE');
    [, $excluded] = linkProduct('VAR_EXCLUDED');

    fakeCatalog([
        variation('VAR_OK', 'ITEM_OK'),
        variation('VAR_ARCHIVED', 'ITEM_ARCHIVED'),
        // Only sold at another location.
        variation('VAR_ELSEWHERE', 'ITEM_OK', ['present_at_all_locations' => false, 'present_at_location_ids' => ['L_OTHER']]),
        variation('VAR_EXCLUDED', 'ITEM_EXCLUDED'),
    ], [
        item('ITEM_OK'),
        item('ITEM_ARCHIVED', ['is_archived' => true]),
        // Everywhere except the sync location.
        item('ITEM_EXCLUDED', extra: ['absent_at_location_ids' => ['L_SYNC']]),
    ]);

    $result = app(VerifySquareLinks::class)->handle();

    expect($result)->toMatchArray(['checked' => 5, 'ok' => 1, 'missing' => 1, 'archived' => 1, 'not_at_location' => 2])
        ->and($ok->fresh()->verification_status)->toBe('ok')
        ->and($missing->fresh()->verification_status)->toBe('missing')
        ->and($archived->fresh()->verification_status)->toBe('archived')
        ->and($elsewhere->fresh()->verification_status)->toBe('not_at_location')
        ->and($excluded->fresh()->verification_status)->toBe('not_at_location')
        ->and($ok->fresh()->verified_at)->not->toBeNull();
});

it('marks a missing link orphaned -- never unlinks it -- and revives it if the item comes back', function () {
    [, $mapping] = linkProduct('VAR_FLAKY');

    // First check: gone. Second check: back.
    Http::fake([
        'connect.squareupsandbox.com/v2/catalog/batch-retrieve*' => Http::sequence()
            ->push(['objects' => [], 'related_objects' => []])
            ->push(['objects' => [variation('VAR_FLAKY', 'ITEM_BACK')], 'related_objects' => [item('ITEM_BACK')]]),
    ]);

    app(VerifySquareLinks::class)->handle();

    expect($mapping->fresh()->sync_status)->toBe('orphaned')
        ->and($mapping->fresh()->trashed())->toBeFalse();

    app(VerifySquareLinks::class)->handle();

    expect($mapping->fresh()->sync_status)->toBe('linked')
        ->and($mapping->fresh()->verification_status)->toBe('ok');
});

it('treats a deleted object as missing', function () {
    [, $mapping] = linkProduct('VAR_DELETED');

    fakeCatalog([variation('VAR_DELETED', 'ITEM_X', ['is_deleted' => true])], [item('ITEM_X')]);

    app(VerifySquareLinks::class)->handle();

    expect($mapping->fresh()->verification_status)->toBe('missing');
});

it('never pushes to an orphaned link', function () {
    [$product, $mapping] = linkProduct('VAR_ORPHAN');
    $mapping->forceFill(['sync_status' => 'orphaned', 'verification_status' => 'missing'])->save();

    Queue::fake([PushInventoryCountJob::class]);
    app(QueueStockPush::class)->handle($product->id, 3);
    Queue::assertNotPushed(PushInventoryCountJob::class);

    // A catalog push must not update the missing object -- nor create a
    // duplicate item on Square in its place.
    PushCatalogObjectJob::dispatchSync($product->id);
    Http::assertNothingSent();
});

it('square:check reports problems', function () {
    [$product] = linkProduct('VAR_GONE_CLI');
    fakeCatalog([]);

    $this->artisan('square:check')
        ->expectsOutputToContain('Square connection: ONLINE')
        ->expectsOutputToContain('0 of 1 product link(s) OK.')
        ->expectsOutputToContain($product->title)
        ->assertSuccessful();
});

it('lets the admin page show only links needing attention', function () {
    [, $fine] = linkProduct('VAR_FINE');
    [, $stale] = linkProduct('VAR_STALE');
    $fine->forceFill(['verification_status' => 'ok'])->save();
    $stale->forceFill(['verification_status' => 'missing', 'sync_status' => 'orphaned'])->save();

    config(['square-sync.access_token' => null]);
    $admin = User::factory()->create(['role' => 'admin']);

    // The page render goes through Inertia's SSR server, like every other page test.
    Http::allowStrayRequests();

    $this->actingAs($admin)->get(route('admin.square.index', ['links' => 'attention']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('onlyNeedingAttention', true)
            ->where('summary.links_needing_attention', 1)
            ->has('mappings.data', 1)
            ->where('mappings.data.0.square_object_id', 'VAR_STALE')
            ->where('mappings.data.0.verification_status', 'missing'));
});

it('relinking a product replaces its stale link', function () {
    [$product, $stale] = linkProduct('VAR_STALE_RELINK');
    $stale->forceFill(['verification_status' => 'missing', 'sync_status' => 'orphaned'])->save();

    Queue::fake([PushInventoryCountJob::class]);

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->post(route('admin.square.link'), [
            'product_id' => $product->id,
            'square_object_id' => 'VAR_NEW',
            'inventory_source' => 'local',
        ])
        ->assertRedirect();

    expect($stale->fresh()->trashed())->toBeTrue()
        ->and(SquareObjectMapping::forItem($product->id)->sole()->square_object_id)->toBe('VAR_NEW')
        ->and(SquareObjectMapping::forItem($product->id)->sole()->verification_status)->toBeNull();
});
