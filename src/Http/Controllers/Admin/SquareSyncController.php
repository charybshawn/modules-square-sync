<?php

namespace Cultpantry\SquareSync\Http\Controllers\Admin;

use App\Actions\GetSiteSetting;
use App\Actions\UpdateSiteSetting;
use App\Http\Controllers\Controller;
use Cultpantry\SquareSync\Actions\FetchSquareInventoryCount;
use Cultpantry\SquareSync\Actions\FetchSquareLocations;
use Cultpantry\SquareSync\Actions\FetchSquareSyncData;
use Cultpantry\SquareSync\Actions\FetchUnlinkedSquareCatalogItems;
use Cultpantry\SquareSync\Actions\GetSquareLocationId;
use Cultpantry\SquareSync\Actions\ReconcileInventoryDrift;
use Cultpantry\SquareSync\Actions\ResolveSquareInventoryDrift;
use Cultpantry\SquareSync\Actions\VerifySquareLinks;
use Cultpantry\SquareSync\Contracts\AuditLog;
use Cultpantry\SquareSync\Contracts\LocalCatalog;
use Cultpantry\SquareSync\Contracts\LocalInventory;
use Cultpantry\SquareSync\Contracts\LocalItem;
use Cultpantry\SquareSync\Jobs\PushInventoryCountJob;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    public function index(Request $request, FetchSquareSyncData $fetchSquareSyncData): Response
    {
        $this->authorize('viewAny', SquareObjectMapping::class);

        return Inertia::render('Vendor/square-sync/Index', $fetchSquareSyncData->handle(
            onlyNeedingAttention: $request->query('links') === 'attention',
        ));
    }

    /**
     * Soft-deletes the mapping (see SquareObjectMapping::unlink()) --
     * the product goes back to the "unmapped" panel, and its sync history
     * survives in case it's relinked later.
     */
    public function unlink(SquareObjectMapping $mapping): RedirectResponse
    {
        $this->authorize('delete', $mapping);

        $label = $mapping->localItem()?->title ?? $mapping->square_object_id;
        $mapping->unlink();

        return redirect()->back()->with('success', "Unlinked '{$label}' from Square.");
    }

    /**
     * Triggers the same whole-catalog reconcile the `square:reconcile`
     * schedule runs -- calls ReconcileInventoryDrift directly (the same
     * Action the console command itself uses) rather than shelling out via
     * Artisan::call(), so the response carries real structured rows for the
     * admin UI's results modal instead of the command's captured console
     * text (which used to get dumped wholesale into the flash message as a
     * raw ASCII table -- unreadable outside a terminal). Report-only by
     * default -- no fix -- matching the command's own safe default. See
     * resolveDrift() below for how a drifted row actually gets corrected.
     */
    public function sync(VerifySquareLinks $verifySquareLinks, ReconcileInventoryDrift $reconcileInventoryDrift): JsonResponse
    {
        // A fresh, unpersisted instance: the 'sync' ability doesn't
        // inspect the model at all (SquareObjectMappingPolicy::sync()
        // only checks $user->isAdmin()), and this endpoint triggers a
        // whole-catalog check, not a single mapping's re-sync. Route and
        // constructor middleware already gate this to admins; this call
        // is the policy layer of the app's required three-layer defense
        // in depth.
        $this->authorize('sync', new SquareObjectMapping);

        try {
            // Links first, so drift is only compared for links that still
            // point at something real (orphaned ones drop out of it).
            $links = $verifySquareLinks->handle();

            return response()->json([
                ...$reconcileInventoryDrift->handle(fix: false),
                'links' => $links,
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => "Square request failed: {$e->getMessage()}"], 502);
        }
    }

    /**
     * Resolves one drifted, already-linked item from the sync-check results
     * table by pushing its local count to Square. Local stock is the source
     * of truth, so there is no "trust Square" resolution -- 'source' is
     * still accepted (and must be 'local') so the request says explicitly
     * which way the overwrite goes.
     */
    public function resolveDrift(Request $request, LocalCatalog $catalog, ResolveSquareInventoryDrift $resolveSquareInventoryDrift): JsonResponse
    {
        $this->authorize('sync', new SquareObjectMapping);

        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'source' => ['required', Rule::in(['local'])],
        ]);

        $item = $this->findItemOrFail($catalog, $validated['product_id']);

        if (! SquareObjectMapping::forItem($item->id)->exists()) {
            return response()->json(['error' => "'{$item->title}' is not currently linked to Square."], 422);
        }

        $quantity = $resolveSquareInventoryDrift->handle($item);

        return response()->json([
            'product_id' => $item->id,
            'source' => $validated['source'],
            'quantity' => $quantity,
        ]);
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
     * Read-only preview for the linking modal: local stock vs. Square's
     * live count for the candidate catalog object, so an admin can see
     * both numbers before choosing which one becomes the starting truth.
     * Makes no changes -- no mapping created, no stock touched. This is
     * what dry-runs the choice link() below actually commits.
     */
    public function linkPreview(Request $request, LocalCatalog $catalog, FetchSquareInventoryCount $fetchSquareInventoryCount): JsonResponse
    {
        $this->authorize('sync', new SquareObjectMapping);

        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'square_object_id' => ['required', 'string', 'max:191'],
        ]);

        $product = $this->findItemOrFail($catalog, $validated['product_id']);

        if (! $product->tracksInventory) {
            return response()->json(['track_inventory' => false]);
        }

        try {
            $squareQuantity = $fetchSquareInventoryCount->handle($validated['square_object_id']);
        } catch (Throwable $e) {
            return response()->json(['error' => "Square request failed: {$e->getMessage()}"], 502);
        }

        return response()->json([
            'track_inventory' => true,
            'local_quantity' => $product->stockQuantity,
            'square_quantity' => $squareQuantity,
        ]);
    }

    /**
     * Manually pairs one Square catalog item (an ITEM_VARIATION, per the
     * mapping table's own contract -- see SquareObjectMapping's class docs)
     * with one local product, chosen by an admin from the catalog-items
     * panel rather than matched automatically by SKU. Reuses linkTo()'s
     * existing idempotent link/relink behavior, same as PullSquareCatalog's
     * SKU-matched links -- there's only one way a mapping row gets created,
     * manual or automatic.
     *
     * For a tracked product, the admin must say which side is correct for
     * this product's starting count -- inventory_source: 'local' pushes
     * the current stock_quantity to Square as the baseline (see
     * linkPreview() for why a freshly-linked item can't just be left alone:
     * without a push, it sits at whatever Square already had -- typically
     * 0 for a brand-new catalog object). 'square' does the reverse: pulls
     * Square's current count and applies it locally, once, via
     * LocalInventory::setFromSquareAtLink() -- the only path by which
     * Square's count is ever copied into local stock, and only because an
     * admin explicitly chose it here.
     */
    public function link(Request $request, LocalCatalog $catalog, LocalInventory $inventory, FetchSquareInventoryCount $fetchSquareInventoryCount): RedirectResponse
    {
        $this->authorize('sync', new SquareObjectMapping);

        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'square_object_id' => ['required', 'string', 'max:191'],
            'square_parent_object_id' => ['nullable', 'string', 'max:191'],
            'inventory_source' => ['nullable', Rule::in(['local', 'square'])],
        ]);

        $product = $this->findItemOrFail($catalog, $validated['product_id']);

        // One link per product: relinking replaces whatever it pointed at
        // before (typically a stale link VerifySquareLinks flagged).
        SquareObjectMapping::forItem($product->id)
            ->where('square_object_id', '!=', $validated['square_object_id'])
            ->get()
            ->each(fn (SquareObjectMapping $old) => $old->unlink());

        SquareObjectMapping::linkTo(
            $product->id,
            $validated['square_object_id'],
            'ITEM_VARIATION',
            $validated['square_parent_object_id'] ?? null,
        );

        $message = "Linked '{$product->title}' to Square.";

        if (! $product->tracksInventory) {
            return redirect()->back()->with('success', $message);
        }

        $source = $validated['inventory_source'] ?? 'local';

        if ($source === 'local') {
            PushInventoryCountJob::dispatch($product->id, $product->stockQuantity)->afterCommit();
            $message = "Linked '{$product->title}' to Square -- pushing current stock ({$product->stockQuantity}) as the baseline.";

            return redirect()->back()->with('success', $message);
        }

        try {
            $squareQuantity = $fetchSquareInventoryCount->handle($validated['square_object_id']);
        } catch (Throwable $e) {
            return redirect()->back()->with('warning', "Linked '{$product->title}' to Square, but couldn't fetch Square's current count: {$e->getMessage()}. Stock levels are out of sync until you pull inventory.");
        }

        $inventory->setFromSquareAtLink(
            itemId: $product->id,
            quantity: $squareQuantity,
            actor: $request->user(),
            metadata: [
                'square_object_id' => $validated['square_object_id'],
                'source' => 'link_initial_sync',
            ],
        );

        return redirect()->back()->with('success', "Linked '{$product->title}' to Square -- set local stock to Square's count ({$squareQuantity}).");
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
        AuditLog $auditLog,
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
        $auditLog->record(
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
     * The contract-backed equivalent of the Rule::exists() +
     * findOrFail() pair these endpoints used before the package stopped
     * knowing the host's products table: a missing item is a validation
     * error on product_id, not a 404, so the admin UI shows it inline.
     */
    private function findItemOrFail(LocalCatalog $catalog, int $itemId): LocalItem
    {
        $item = $catalog->find($itemId);

        if (! $item) {
            throw ValidationException::withMessages(['product_id' => 'The selected product id is invalid.']);
        }

        return $item;
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
