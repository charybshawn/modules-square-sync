<?php

namespace Cultpantry\SquareSync\Actions;

use Cultpantry\SquareSync\Contracts\AuditLog;
use Cultpantry\SquareSync\Contracts\LocalCatalog;
use Cultpantry\SquareSync\Contracts\LocalItem;
use Cultpantry\SquareSync\Http\Resources\SquareObjectMappingResource;
use Cultpantry\SquareSync\Models\SquareImportedSale;
use Cultpantry\SquareSync\Models\SquareInventoryChange;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Assembles every read-model the admin Square Sync page (WP7) needs, in one
 * place, so the controller stays a thin Inertia::render() dispatcher (per
 * this app's Actions-only rule) and the page's panels -- connection status,
 * linked mappings, unmapped items, drift, and recent activity -- come
 * from a single, independently testable entry point instead of being built
 * up across several controller methods.
 */
class FetchSquareSyncData
{
    private const MAPPINGS_PER_PAGE = 15;

    private const UNMAPPED_PRODUCTS_LIMIT = 100;

    private const DRIFT_EVENTS_LIMIT = 10;

    private const RECENT_ACTIVITY_ROOTS_LIMIT = 15;

    public function __construct(
        private readonly GetSquareLocationId $getLocationId,
        private readonly FetchSquareLocations $fetchLocations,
        private readonly LocalCatalog $catalog,
        private readonly AuditLog $auditLog,
    ) {}

    /**
     * @return array{
     *     connection: array,
     *     mappings: AnonymousResourceCollection,
     *     unmappedProducts: array,
     *     driftEvents: array,
     *     recentActivity: array,
     *     summary: array,
     * }
     */
    public function handle(): array
    {
        return [
            'connection' => $this->connectionStatus(),
            'mappings' => $this->mappings(),
            'unmappedProducts' => $this->unmappedProducts(),
            'driftEvents' => $this->driftEvents(),
            'recentActivity' => $this->recentActivity(),
            'summary' => $this->summary(),
        ];
    }

    /**
     * Last-30-days counts for the page's stat block, from this module's
     * own ledgers -- what the sync has actually done lately, without
     * reaching into the host's orders.
     *
     * @return array{sales_recorded: int, refunds_recorded: int, stock_changes_applied: int, last_sale_at: string|null}
     */
    private function summary(): array
    {
        $since = now()->subDays(30);
        $lastSaleAt = SquareImportedSale::where('kind', 'sale')->max('occurred_at');

        return [
            'sales_recorded' => SquareImportedSale::where('kind', 'sale')->where('created_at', '>=', $since)->count(),
            'refunds_recorded' => SquareImportedSale::where('kind', 'refund')->where('created_at', '>=', $since)->count(),
            'stock_changes_applied' => SquareInventoryChange::where('created_at', '>=', $since)->count(),
            'last_sale_at' => $lastSaleAt !== null ? Carbon::parse($lastSaleAt)->toIso8601String() : null,
        ];
    }

    /**
     * config('square-sync.access_token') itself must never reach the
     * browser, not even masked -- presence/absence is all an admin needs
     * in order to diagnose a missing .env value. There is an existing test
     * asserting the token value never appears anywhere in this page's
     * response; nothing added here should change that.
     */
    private function connectionStatus(): array
    {
        $accessTokenConfigured = filled(config('square-sync.access_token'));
        $selectedLocationId = $this->getLocationId->handle();
        $locationIdConfigured = filled($selectedLocationId);

        return [
            'access_token_configured' => $accessTokenConfigured,
            'location_id_configured' => $locationIdConfigured,
            'configured' => $accessTokenConfigured && $locationIdConfigured,
            'environment' => config('square-sync.environment'),
            // Lets the page render a <select> of real Square locations
            // instead of a raw text field -- empty when the token is
            // missing or Square is unreachable, see FetchSquareLocations.
            'locations' => $this->fetchLocations->handle(),
            'selected_location_id' => $selectedLocationId,
            'location_source' => $this->getLocationId->source(),
        ];
    }

    /**
     * Trashed items are included in the lookup -- a mapping itself is only
     * ever fetched live (unlink() soft-deletes the *mapping*, not the
     * item), but the linked item may have been separately archived
     * (PullSquareCatalogDelta archives it on our side when it's removed
     * from Square). Excluding those would silently render a blank
     * title/sku instead of the item's last-known name. One findMany() for
     * the whole page rather than a lookup per row.
     */
    private function mappings(): AnonymousResourceCollection
    {
        $paginator = SquareObjectMapping::query()
            ->orderByDesc('id')
            ->paginate(self::MAPPINGS_PER_PAGE)
            ->withQueryString();

        $items = $this->catalog->findMany(
            $paginator->getCollection()->pluck('mappable_id')->all(),
            withTrashed: true,
        );

        $paginator->getCollection()->each(
            fn (SquareObjectMapping $mapping) => $mapping->setLocalItem($items[$mapping->mappable_id] ?? null),
        );

        return SquareObjectMappingResource::collection($paginator);
    }

    /**
     * Live local items with no live SquareObjectMapping row -- a
     * soft-deleted (unlinked) mapping doesn't count as mapped, matching
     * linkTo()'s own withTrashed()-aware idempotency (unlinking and
     * re-linking the same item is meant to work). Capped for payload
     * size; 'total' is reported separately so the page can say "and N
     * more" honestly rather than implying the list is exhaustive.
     */
    private function unmappedProducts(): array
    {
        $mappedItemIds = SquareObjectMapping::forLocalCatalog()->pluck('mappable_id')->all();

        $result = $this->catalog->listExcluding($mappedItemIds, self::UNMAPPED_PRODUCTS_LIMIT);

        return [
            'items' => array_map(fn (LocalItem $item) => [
                'id' => $item->id,
                'title' => $item->title,
                'sku' => $item->sku,
            ], array_values($result['items'])),
            'total' => $result['total'],
        ];
    }

    private function driftEvents(): array
    {
        return $this->auditLog->recentOfType('square.drift_detected', self::DRIFT_EVENTS_LIMIT);
    }

    /**
     * Recent square.* activity, one sync run per group, so a whole
     * reconcile/pull/push tree reads as one entry rather than a wall of
     * unrelated rows -- see AuditLog::recentActivity() for the grouping
     * contract the host implements.
     */
    private function recentActivity(): array
    {
        return $this->auditLog->recentActivity('square', self::RECENT_ACTIVITY_ROOTS_LIMIT);
    }
}
