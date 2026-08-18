<?php

namespace Cultpantry\SquareSync\Actions;

use App\Actions\RecordEvent;
use App\Actions\SyncInventory;
use App\Models\Event;
use App\Models\Product;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Illuminate\Support\Facades\Log;

/**
 * Applies a single inventory count entry from an inbound
 * inventory.count.updated webhook to the local product it maps to.
 *
 * Unlike catalog.version.updated, Square's inventory webhook carries the
 * actual count in the payload -- no follow-up API call is needed, which
 * keeps this a plain apply-and-record rather than a pull.
 */
class ApplyInventoryCountFromSquare
{
    public function __construct(
        private readonly SyncInventory $syncInventory,
        private readonly RecordEvent $recordEvent,
        private readonly GetSquareLocationId $getLocationId,
    ) {}

    /**
     * @param  array  $count  One entry of data.object.inventory_counts from
     *                        Square's payload, e.g. ['catalog_object_id' =>
     *                        '...', 'state' => 'IN_STOCK', 'quantity' =>
     *                        '8', 'location_id' => '...'].
     */
    public function handle(array $count, ?string $correlationId = null, ?Event $parentEvent = null): void
    {
        $squareObjectId = $count['catalog_object_id'] ?? null;

        if (blank($squareObjectId)) {
            Log::warning('Square inventory count missing catalog_object_id, skipping', ['count' => $count]);

            return;
        }

        // Square reports every tracked state (IN_STOCK, WASTE, SOLD, ...)
        // per location; only IN_STOCK is "how many units are available to
        // sell", which is the only number stock_quantity represents.
        $state = $count['state'] ?? 'IN_STOCK';
        if ($state !== 'IN_STOCK') {
            return;
        }

        // Square reports counts per location, and products.stock_quantity is
        // a single number with no location dimension -- so it can only ever
        // mean "the configured location's stock". Without this guard, a
        // second Square location would overwrite stock_quantity with its own
        // count on every webhook: location A (10 units) and location B (3)
        // would take turns clobbering each other, and the outbound push would
        // then send whichever number won back to A as gospel.
        //
        // Matches the outbound contract exactly -- PushInventoryCountJob
        // writes to whatever GetSquareLocationId resolves and nowhere else.
        // Supporting several locations properly means deciding what the
        // single local number represents (sum? one designated location?) and
        // is a schema-level change, not a filter.
        $configuredLocationId = $this->getLocationId->handle();
        $countLocationId = $count['location_id'] ?? null;

        if (filled($configuredLocationId) && filled($countLocationId) && $countLocationId !== $configuredLocationId) {
            Log::info('Square inventory count for a non-configured location, skipping', [
                'square_object_id' => $squareObjectId,
                'count_location_id' => $countLocationId,
                'configured_location_id' => $configuredLocationId,
            ]);

            return;
        }

        $mapping = SquareObjectMapping::forSquareId($squareObjectId)->first();

        if (! $mapping) {
            Log::info('Square inventory count for unmapped object, skipping', ['square_object_id' => $squareObjectId]);

            return;
        }

        $product = $mapping->mappable;

        if (! $product instanceof Product) {
            Log::warning('Square object mapping does not resolve to a Product, skipping', [
                'square_object_mapping_id' => $mapping->id,
                'square_object_id' => $squareObjectId,
            ]);

            return;
        }

        // Square sends quantity as a numeric string (see
        // InventoryApi::physicalCount's docblock for the mirror image of
        // this on the outbound side).
        $quantity = (int) ($count['quantity'] ?? 0);

        // reason: SQUARE_PULL is load-bearing -- it's what the outbound
        // StockUpdated listener checks to avoid pushing this write straight
        // back to Square, which would otherwise ping-pong the two systems
        // forever on every inventory change.
        $this->syncInventory->applyChange(
            product: $product,
            newQuantity: $quantity,
            reason: SyncInventory::REASONS['SQUARE_PULL'],
            metadata: [
                'square_object_id' => $squareObjectId,
                'square_location_id' => $count['location_id'] ?? null,
            ],
        );

        $mapping->markPulled();

        $this->recordEvent->handle(
            type: 'square.inventory_pulled',
            description: "{$product->title} stock pulled from Square ({$quantity})",
            subject: $product,
            metadata: [
                'square_object_id' => $squareObjectId,
                'quantity' => $quantity,
            ],
            severity: 'info',
            direction: 'inbound',
            correlationId: $correlationId,
            parentEvent: $parentEvent,
        );
    }
}
