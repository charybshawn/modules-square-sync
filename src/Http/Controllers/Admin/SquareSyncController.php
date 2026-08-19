<?php

namespace Cultpantry\SquareSync\Http\Controllers\Admin;

use App\Actions\GetSiteSetting;
use App\Actions\RecordEvent;
use App\Actions\UpdateSiteSetting;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Cultpantry\SquareSync\Actions\FetchSquareLocations;
use Cultpantry\SquareSync\Actions\FetchSquareSyncData;
use Cultpantry\SquareSync\Actions\FetchUnlinkedSquareCatalogItems;
use Cultpantry\SquareSync\Actions\GetSquareLocationId;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SquareSyncController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function ($request, $next) {
                abort_unless($request->user()?->isAdmin(), 403, 'Admin access required.');

                return $next($request);
            }),
            new Middleware(function ($request, $next) {
                abort_unless(app(GetSiteSetting::class)->handle('modules.cultpantry/square-sync.enabled', true), 404);

                return $next($request);
            }),
        ];
    }

    public function index(FetchSquareSyncData $fetchSquareSyncData): Response
    {
        $this->authorize('viewAny', SquareObjectMapping::class);

        return Inertia::render('Vendor/square-sync/Index', $fetchSquareSyncData->handle());
    }

    /**
     * Soft-deletes the mapping (see SquareObjectMapping::unlink()) --
     * the product goes back to the "unmapped" panel, and its sync history
     * survives in case it's relinked later.
     */
    public function unlink(SquareObjectMapping $mapping): RedirectResponse
    {
        $this->authorize('delete', $mapping);

        $label = $mapping->mappable?->title ?? $mapping->square_object_id;
        $mapping->unlink();

        return redirect()->back()->with('success', "Unlinked '{$label}' from Square.");
    }

    /**
     * Triggers the same whole-catalog reconcile the `square:reconcile`
     * schedule runs, via Artisan::call() rather than reimplementing any of
     * its drift-detection logic here (see ReconcileSquareInventory).
     * Report-only by default -- no --fix -- matching the command's own
     * safe default; a destructive "fix everything" button is a bigger
     * decision than this page currently offers.
     */
    public function sync(): RedirectResponse
    {
        // A fresh, unpersisted instance: the 'sync' ability doesn't
        // inspect the model at all (SquareObjectMappingPolicy::sync()
        // only checks $user->isAdmin()), and this endpoint triggers a
        // whole-catalog check, not a single mapping's re-sync. Route and
        // constructor middleware already gate this to admins; this call
        // is the policy layer of the app's required three-layer defense
        // in depth.
        $this->authorize('sync', new SquareObjectMapping);

        return $this->runArtisanCommand('square:reconcile', [], 'Square reconcile check completed.');
    }

    /**
     * Links Square catalog items to local products by SKU, via
     * `php artisan square:pull-catalog` -- same Artisan::call() wrapper
     * pattern as sync() above, not a reimplementation of its matching
     * logic. dry_run previews matches without writing any
     * SquareObjectMapping rows, mirroring the command's own --dry-run flag
     * and giving the admin UI the same safe-by-default shape sync() has.
     */
    public function pullCatalog(Request $request): RedirectResponse
    {
        $this->authorize('sync', new SquareObjectMapping);

        $dryRun = $request->boolean('dry_run');

        return $this->runArtisanCommand(
            'square:pull-catalog',
            $dryRun ? ['--dry-run' => true] : [],
            'Square catalog link check completed.',
        );
    }

    /**
     * On-demand JSON fetch of every Square catalog item not yet linked to a
     * local product -- the data source for the admin page's manual-linking
     * panel. A GET called from the frontend via axios rather than an
     * Inertia prop (contrast index()'s other panels): walking the whole
     * Square catalog is too heavy to redo on every page load the way
     * FetchSquareLocations does, so it only runs when an admin actually
     * clicks "Download Catalog".
     *
     * Errors are surfaced as JSON rather than an Inertia flash message --
     * this response never goes through Inertia at all -- but the intent
     * matches runArtisanCommand()'s: a failed Square call should read as a
     * friendly message here, not a raw 500 in the browser console.
     */
    public function catalogItems(FetchUnlinkedSquareCatalogItems $fetchCatalogItems): JsonResponse
    {
        $this->authorize('sync', new SquareObjectMapping);

        try {
            $items = $fetchCatalogItems->handle();
        } catch (Throwable $e) {
            return response()->json(['error' => "Square request failed: {$e->getMessage()}"], 502);
        }

        return response()->json(['items' => $items]);
    }

    /**
     * Manually pairs one Square catalog item (an ITEM_VARIATION, per the
     * mapping table's own contract -- see SquareObjectMapping's class docs)
     * with one local product, chosen by an admin from the catalog-items
     * panel rather than matched automatically by SKU. Reuses linkTo()'s
     * existing idempotent link/relink behavior, same as PullSquareCatalog's
     * SKU-matched links -- there's only one way a mapping row gets created,
     * manual or automatic.
     */
    public function link(Request $request): RedirectResponse
    {
        $this->authorize('sync', new SquareObjectMapping);

        $validated = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'square_object_id' => ['required', 'string', 'max:191'],
            'square_parent_object_id' => ['nullable', 'string', 'max:191'],
        ]);

        $product = Product::findOrFail($validated['product_id']);

        SquareObjectMapping::linkTo(
            $product,
            $validated['square_object_id'],
            'ITEM_VARIATION',
            $validated['square_parent_object_id'] ?? null,
        );

        return redirect()->back()->with('success', "Linked '{$product->title}' to Square.");
    }

    /**
     * Saves which Square location this app syncs inventory with -- the
     * whole point of this endpoint (see GetSquareLocationId) is that an
     * admin no longer has to edit SQUARE_LOCATION_ID in .env and redeploy
     * just to point the sync at a different location.
     *
     * The candidate id is validated against FetchSquareLocations's live
     * result rather than accepted as an arbitrary string -- a typo'd id
     * would otherwise sit silently unused (every push/pull would then 404
     * or drift-report against a location that doesn't exist) instead of
     * failing loudly here where an admin can immediately correct it.
     */
    public function updateLocation(
        Request $request,
        FetchSquareLocations $fetchLocations,
        UpdateSiteSetting $updateSetting,
        RecordEvent $recordEvent,
        GetSquareLocationId $getLocationId,
    ): RedirectResponse {
        // Reuses the 'sync' ability rather than 'update' -- like sync()
        // above, this isn't editing a SquareObjectMapping record, it's an
        // account-wide sync setting, and a fresh unpersisted instance is
        // enough since the ability only checks $user->isAdmin().
        $this->authorize('sync', new SquareObjectMapping);

        $availableLocationIds = collect($fetchLocations->handle())->pluck('id')->all();

        $validated = $request->validate([
            'location_id' => [
                'required',
                'string',
                'max:64',
                Rule::in($availableLocationIds),
            ],
        ], [
            // Rule::in() fails on an empty haystack too (no id can ever
            // match), so an admin who submits while Square is unreachable
            // sees why saving didn't work rather than a generic "invalid".
            'location_id.in' => $availableLocationIds === []
                ? 'Square locations could not be loaded right now -- check the access token and try again.'
                : 'Choose one of the locations Square returned for this account.',
        ]);

        // Read before the write, or the audit row records the new value twice.
        $previousLocationId = $getLocationId->handle();

        $updateSetting->handle(GetSquareLocationId::SETTING_KEY, $validated['location_id']);

        // Worth its own type rather than the generic admin bucket: changing
        // this repoints every push and every accepted inbound count at a
        // different Square location, so when stock later looks wrong, this
        // is the first event you'd want to find. Recording the previous
        // value makes the audit row enough on its own to explain a
        // divergence without cross-referencing settings history.
        $recordEvent->handle(
            type: 'square.location_changed',
            description: "Square sync location changed to {$validated['location_id']}",
            actor: $request->user(),
            metadata: [
                'setting' => GetSquareLocationId::SETTING_KEY,
                'previous_value' => $previousLocationId,
                'new_value' => $validated['location_id'],
            ],
            severity: 'info',
        );

        return redirect()->back()->with('success', 'Square sync location updated.');
    }

    /**
     * Shared by sync() and pullCatalog() -- both just wrap an Artisan
     * command for this page's "Actions" menu. The commands themselves are
     * deliberately allowed to let a SquareException bubble when run from
     * the CLI (a failed hourly `square:reconcile` run should show up as a
     * failed scheduled job for monitoring, not swallow the failure), but
     * an admin clicking a button here should never see a raw stack trace
     * just because Square rejected the request -- same "degrade to an
     * empty/friendly result" tradeoff FetchSquareLocations already makes.
     */
    private function runArtisanCommand(string $command, array $parameters, string $fallbackMessage): RedirectResponse
    {
        try {
            Artisan::call($command, $parameters);
        } catch (Throwable $e) {
            return redirect()->back()->with('warning', "Square request failed: {$e->getMessage()}");
        }

        $output = trim(Artisan::output());

        return redirect()->back()->with('success', $output !== '' ? $output : $fallbackMessage);
    }
}
