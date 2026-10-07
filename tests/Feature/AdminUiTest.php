<?php

use App\Actions\UpdateSiteSetting;
use App\Models\Event;
use App\Models\Product;
use App\Models\User;
use App\Support\AdminNav;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

describe('square sync admin ui', function () {
    beforeEach(function () {
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->customer = User::factory()->create(['role' => 'customer']);
    });

    it('registers its routes with the web middleware group', function () {
        // Regression guard: routes loaded via loadRoutesFrom() in a
        // module's ServiceProvider don't get the 'web' group automatically
        // the way routes/web.php does -- see the identical guard in
        // CostingModuleAccessTest for the bug this protects against.
        $route = Route::getRoutes()->getByName('admin.square.index');
        expect($route->middleware())->toContain('web');
    });

    it('lists unlinked Square catalog items with price, archived status and categories', function () {
        config([
            'square-sync.access_token' => 'sq0atp-test-token',
            'square-sync.base_url' => 'https://connect.squareupsandbox.com',
        ]);
        SquareObjectMapping::linkTo(Product::factory()->create()->id, 'SQ_VAR_LINKED', 'ITEM_VARIATION');

        Http::fake([
            'connect.squareupsandbox.com/v2/catalog/list*' => Http::response(['objects' => [
                ['type' => 'CATEGORY', 'id' => 'CAT_GREENS', 'category_data' => ['name' => 'Microgreens']],
                ['type' => 'CATEGORY', 'id' => 'CAT_MARKET', 'category_data' => ['name' => 'Market']],
                ['type' => 'ITEM', 'id' => 'SQ_ITEM_PEA', 'item_data' => [
                    'name' => 'Pea Shoots',
                    'categories' => [['id' => 'CAT_MARKET'], ['id' => 'CAT_GREENS']],
                    'reporting_category' => ['id' => 'CAT_GREENS'],
                ]],
                // Legacy single category_id still resolves.
                ['type' => 'ITEM', 'id' => 'SQ_ITEM_OLD', 'item_data' => ['name' => 'Old Mix', 'is_archived' => true, 'category_id' => 'CAT_GREENS']],
                ['type' => 'ITEM_VARIATION', 'id' => 'SQ_VAR_PEA', 'item_variation_data' => [
                    'item_id' => 'SQ_ITEM_PEA', 'name' => 'Regular', 'sku' => 'PRD-PEA',
                    'price_money' => ['amount' => 550, 'currency' => 'CAD'],
                ]],
                ['type' => 'ITEM_VARIATION', 'id' => 'SQ_VAR_OLD', 'item_variation_data' => [
                    'item_id' => 'SQ_ITEM_OLD', 'name' => 'Large', 'pricing_type' => 'VARIABLE_PRICING',
                ]],
                ['type' => 'ITEM_VARIATION', 'id' => 'SQ_VAR_LINKED', 'item_variation_data' => ['item_id' => 'SQ_ITEM_PEA']],
            ]], 200),
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('admin.square.catalog-items'))
            ->assertOk()
            ->assertExactJson(['items' => [
                [
                    'square_object_id' => 'SQ_VAR_OLD', 'square_parent_object_id' => 'SQ_ITEM_OLD',
                    'name' => 'Old Mix — Large', 'sku' => null, 'price' => null, 'currency' => null, 'archived' => true,
                    'categories' => ['Microgreens'],
                ],
                [
                    'square_object_id' => 'SQ_VAR_PEA', 'square_parent_object_id' => 'SQ_ITEM_PEA',
                    'name' => 'Pea Shoots', 'sku' => 'PRD-PEA', 'price' => 5.5, 'currency' => 'CAD', 'archived' => false,
                    'categories' => ['Market', 'Microgreens'],
                ],
            ]]);
    });

    it('can be loaded by an admin and returns the expected Inertia props', function () {
        $product = Product::factory()->create();
        SquareObjectMapping::linkTo($product->id, 'SQ_ITEM_ABC', 'ITEM');

        $this->actingAs($this->admin)
            ->get(route('admin.square.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Vendor/square-sync/Index')
                ->has('connection')
                ->has('mappings')
                ->has('mappings.data', 1)
                ->has('unmappedProducts')
                ->has('driftEvents')
                ->has('recentActivity')
            );
    });

    it('returns configured=false when Square credentials are not set', function () {
        config(['square-sync.access_token' => null, 'square-sync.location_id' => null]);

        $this->actingAs($this->admin)
            ->get(route('admin.square.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('connection.access_token_configured', false)
                ->where('connection.location_id_configured', false)
                ->where('connection.configured', false)
            );
    });

    it('returns configured=true when both Square credentials are set', function () {
        config(['square-sync.access_token' => 'test-token-value', 'square-sync.location_id' => 'L123']);

        // A token being configured means FetchSquareLocations now actually
        // calls out to Square as part of loading this page -- fake it so
        // this stays a hermetic test rather than a real network call.
        Http::fake(['connect.squareupsandbox.com/*' => Http::response(['locations' => []], 200)]);

        $this->actingAs($this->admin)
            ->get(route('admin.square.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('connection.access_token_configured', true)
                ->where('connection.location_id_configured', true)
                ->where('connection.configured', true)
            );
    });

    it('never renders the access token value anywhere in the response', function () {
        config([
            'square-sync.access_token' => 'SECRET_SQUARE_TOKEN_VALUE',
            'square-sync.location_id' => 'L123',
        ]);

        // Same as above: a configured token means this page load also
        // fetches Square's location list, so it needs a fake response.
        Http::fake(['connect.squareupsandbox.com/*' => Http::response(['locations' => [
            ['id' => 'L123', 'name' => 'Main St', 'status' => 'ACTIVE'],
        ]], 200)]);

        $response = $this->actingAs($this->admin)->get(route('admin.square.index'));

        $response->assertOk();
        expect($response->getContent())->not->toContain('SECRET_SQUARE_TOKEN_VALUE');
    });

    it('lists unmapped local products separately from linked mappings', function () {
        $linked = Product::factory()->create(['title' => 'Linked Sourdough']);
        $unmapped = Product::factory()->create(['title' => 'Unmapped Bagel']);
        SquareObjectMapping::linkTo($linked->id, 'SQ_ITEM_LINKED', 'ITEM');

        $this->actingAs($this->admin)
            ->get(route('admin.square.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('mappings.data.0.product_title', 'Linked Sourdough')
                ->where('unmappedProducts.items', fn ($items) => collect($items)->pluck('title')->contains('Unmapped Bagel')
                    && ! collect($items)->pluck('title')->contains('Linked Sourdough'))
            );
    });

    it('customer gets a 403', function () {
        $this->actingAs($this->customer)
            ->get(route('admin.square.index'))
            ->assertForbidden();
    });

    it('guest is redirected to login', function () {
        $this->get(route('admin.square.index'))
            ->assertRedirect(route('login'));
    });

    it('404s for admin when the module is disabled via the Settings toggle', function () {
        (new UpdateSiteSetting)->handle('modules.cultpantry/square-sync.enabled', false);

        $this->actingAs($this->admin)
            ->get(route('admin.square.index'))
            ->assertNotFound();

        (new UpdateSiteSetting)->handle('modules.cultpantry/square-sync.enabled', true);
    });

    it('unlinks a mapping via soft delete and it disappears from the linked list', function () {
        $product = Product::factory()->create();
        $mapping = SquareObjectMapping::linkTo($product->id, 'SQ_ITEM_TO_UNLINK', 'ITEM');

        $this->actingAs($this->admin)
            ->post(route('admin.square.unlink', $mapping))
            ->assertRedirect();

        expect(SquareObjectMapping::find($mapping->id))->toBeNull();
        expect(SquareObjectMapping::withTrashed()->find($mapping->id))->not->toBeNull();

        $this->actingAs($this->admin)
            ->get(route('admin.square.index'))
            ->assertInertia(fn ($page) => $page
                ->where('mappings.data', [])
                ->where('unmappedProducts.items', fn ($items) => collect($items)->pluck('id')->contains($product->id))
            );
    });

    it('blocks a customer from unlinking a mapping', function () {
        $product = Product::factory()->create();
        $mapping = SquareObjectMapping::linkTo($product->id, 'SQ_ITEM_CUSTOMER_BLOCK', 'ITEM');

        $this->actingAs($this->customer)
            ->post(route('admin.square.unlink', $mapping))
            ->assertForbidden();

        expect(SquareObjectMapping::find($mapping->id))->not->toBeNull();
    });

    it('blocks a guest from unlinking a mapping', function () {
        $product = Product::factory()->create();
        $mapping = SquareObjectMapping::linkTo($product->id, 'SQ_ITEM_GUEST_BLOCK', 'ITEM');

        $this->post(route('admin.square.unlink', $mapping))
            ->assertRedirect(route('login'));

        expect(SquareObjectMapping::find($mapping->id))->not->toBeNull();
    });

    it('the manual sync endpoint is admin-gated: customer forbidden, guest redirected, admin allowed', function () {
        $this->post(route('admin.square.sync'))
            ->assertRedirect(route('login'));

        $this->actingAs($this->customer)
            ->post(route('admin.square.sync'))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->post(route('admin.square.sync'))
            ->assertOk()
            ->assertJson(['checked' => 0, 'drifted' => 0, 'corrected' => 0, 'rows' => []]);
    });

    it('surfaces recent square.drift_detected events in the drift panel', function () {
        $product = Product::factory()->create();

        Event::create([
            'type' => 'square.drift_detected',
            'description' => "{$product->title} stock drifted from Square",
            'subject_type' => Product::class,
            'subject_id' => $product->id,
            'severity' => 'warning',
            'direction' => 'inbound',
            'created_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.square.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('driftEvents', 1)
                ->where('driftEvents.0.type', 'square.drift_detected')
            );
    });

    it('groups recent square activity by correlation_id into one tree', function () {
        $correlationId = (string) Str::uuid();

        $request = Event::create([
            'type' => 'square.request',
            'description' => 'Square API request',
            'severity' => 'info',
            'direction' => 'outbound',
            'correlation_id' => $correlationId,
            'created_at' => now()->subSecond(),
        ]);

        Event::create([
            'type' => 'square.response',
            'description' => 'Square API response',
            'severity' => 'info',
            'direction' => 'inbound',
            'correlation_id' => $correlationId,
            'parent_event_id' => $request->id,
            'created_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.square.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('recentActivity', 1)
                ->where('recentActivity.0.correlation_id', $correlationId)
                ->has('recentActivity.0.events', 2)
                ->where('recentActivity.0.events.0.type', 'square.request')
                ->where('recentActivity.0.events.1.type', 'square.response')
            );
    });

    it('appears in the admin nav as a top-level item when enabled', function () {
        $names = collect(AdminNav::all())->pluck('name');

        expect($names)->toContain('Square Sync');
    });

    it('disappears from the admin nav when disabled', function () {
        (new UpdateSiteSetting)->handle('modules.cultpantry/square-sync.enabled', false);

        $names = collect(AdminNav::all())->pluck('name');
        expect($names)->not->toContain('Square Sync');

        (new UpdateSiteSetting)->handle('modules.cultpantry/square-sync.enabled', true);
    });
});
