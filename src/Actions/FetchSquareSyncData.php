<?php

namespace Cultpantry\SquareSync\Actions;

use App\Models\Event;
use App\Models\Product;
use Cultpantry\SquareSync\Http\Resources\SquareObjectMappingResource;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Assembles every read-model the admin Square Sync page (WP7) needs, in one
 * place, so the controller stays a thin Inertia::render() dispatcher (per
 * this app's Actions-only rule) and the page's panels -- connection status,
 * linked mappings, unmapped products, drift, and recent activity -- come
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
    ) {}

    /**
     * @return array{
     *     connection: array,
     *     mappings: AnonymousResourceCollection,
     *     unmappedProducts: array,
     *     driftEvents: array,
     *     recentActivity: array,
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
     * withTrashed() on the mappable constraint, not the base query -- a
     * mapping itself is only ever fetched live (unlink() soft-deletes the
     * *mapping*, not the product), but the linked product may have been
     * separately archived (ArchiveSquareCatalogObjectJob soft-deletes it
     * on our side when it's removed from Square). Without withTrashed()
     * here that row would silently render a blank title/sku instead of
     * the product's last-known name.
     */
    private function mappings(): AnonymousResourceCollection
    {
        $paginator = SquareObjectMapping::query()
            ->with(['mappable' => fn ($query) => $query->withTrashed()])
            ->orderByDesc('id')
            ->paginate(self::MAPPINGS_PER_PAGE)
            ->withQueryString();

        return SquareObjectMappingResource::collection($paginator);
    }

    /**
     * Local, non-deleted products with no live SquareObjectMapping row --
     * a soft-deleted (unlinked) mapping doesn't count as mapped, matching
     * linkTo()'s own withTrashed()-aware idempotency (unlinking and
     * re-linking the same product is meant to work). Capped for payload
     * size; 'total' is reported separately so the page can say "and N
     * more" honestly rather than implying the list is exhaustive.
     */
    private function unmappedProducts(): array
    {
        $mappedProductIds = SquareObjectMapping::query()
            ->where('mappable_type', Product::class)
            ->pluck('mappable_id');

        $query = Product::query()
            ->whereNotIn('id', $mappedProductIds)
            ->orderBy('title');

        $total = $query->count();

        $items = $query->limit(self::UNMAPPED_PRODUCTS_LIMIT)
            ->get(['id', 'title', 'sku'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'title' => $product->title,
                'sku' => $product->sku,
            ])
            ->all();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    private function driftEvents(): array
    {
        return Event::ofType('square.drift_detected')
            ->orderByDesc('created_at')
            ->limit(self::DRIFT_EVENTS_LIMIT)
            ->get()
            ->map(fn (Event $event) => $this->mapEvent($event))
            ->all();
    }

    /**
     * Recent square.* activity, one sync run per group, so a whole
     * reconcile/pull/push tree reads as one entry rather than a wall of
     * unrelated rows. Grouped on correlation_id per the WP7 spec, anchored
     * on root events (no parent_event_id) -- most square.* flows do build
     * a real parent/child chain (SquareClient::request()'s request/
     * response pair, the webhook controller's parentEvent threading), but
     * several standalone writers (ReconcileSquareInventory's drift events,
     * the outbound push jobs) never pass a parentEvent at all, so a purely
     * parent/child tree would show those as isolated singletons even when
     * they *do* share a correlation_id with something else in the group.
     * correlatedEvents() is walked in afterwards to catch that -- guarded
     * on a non-null correlation_id, since Event::correlatedEvents()'s
     * `where('correlation_id', $this->correlation_id)` degrades to
     * whereNull() for the many square.* events with none, which would
     * otherwise pull in every unrelated null-correlation event in the
     * table.
     */
    private function recentActivity(): array
    {
        $roots = Event::ofCategory('square')
            ->roots()
            ->orderByDesc('created_at')
            ->limit(self::RECENT_ACTIVITY_ROOTS_LIMIT)
            ->get();

        return $roots->map(function (Event $root) {
            $events = $this->flattenTree($root->getEventTree());
            $seenIds = array_column($events, 'id');

            if ($root->correlation_id !== null) {
                foreach ($root->correlatedEvents()->get() as $correlated) {
                    if (! in_array($correlated->id, $seenIds, true)) {
                        $events[] = $this->mapEvent($correlated) + ['depth' => 1];
                        $seenIds[] = $correlated->id;
                    }
                }
            }

            return [
                'correlation_id' => $root->correlation_id,
                'started_at' => $root->created_at?->toIso8601String(),
                'events' => $events,
            ];
        })->all();
    }

    /**
     * @return array<int, array>
     */
    private function flattenTree(array $tree, int $depth = 0): array
    {
        $flattened = [$this->mapEvent($tree['event']) + ['depth' => $depth]];

        foreach ($tree['children'] as $child) {
            array_push($flattened, ...$this->flattenTree($child, $depth + 1));
        }

        return $flattened;
    }

    private function mapEvent(Event $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type,
            'type_label' => $event->type_label,
            'description' => $event->description,
            'severity' => $event->severity,
            'direction' => $event->direction,
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }
}
