<?php

namespace Cultpantry\SquareSync\Jobs;

use App\Actions\RecordEvent;
use App\Models\Product;
use Cultpantry\SquareSync\Actions\GetSquareLocationId;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Square\InventoryApi;
use Cultpantry\SquareSync\Square\SquareClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pushes a single product's stock_quantity to Square as an absolute
 * PHYSICAL_COUNT (via InventoryApi::physicalCount() -- idempotent under
 * retry, unlike an ADJUSTMENT delta which would double-apply if the same
 * retry landed twice).
 *
 * Constructor takes scalars (product id, quantity) rather than the Product
 * model on purpose. SerializesModels re-fetches a queued job's model
 * arguments by primary key at execution time, which can be seconds --or,
 * after a retry's backoff, up to a minute-- after this job was created. If
 * stock_quantity had changed again in the meantime (another order placed,
 * another admin edit), re-fetching would silently push that *newer*
 * quantity instead of the one this job was actually dispatched for,
 * potentially racing whatever job got queued for that later change and
 * landing them on Square out of order. Capturing $quantity as a plain int
 * at dispatch time freezes exactly the value this job is responsible for.
 */
class PushInventoryCountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $productId,
        public readonly int $quantity,
    ) {}

    public function handle(SquareClient $client, RecordEvent $recordEvent, GetSquareLocationId $getLocationId): void
    {
        $mapping = SquareObjectMapping::query()
            ->where('mappable_type', Product::class)
            ->where('mappable_id', $this->productId)
            ->first();

        // The mapping may have been unlinked between dispatch and
        // execution (e.g. the product was soft-deleted and archived on
        // Square in the meantime) -- nothing left to push a count to.
        if (! $mapping) {
            return;
        }

        // Deterministic, not random: derived entirely from stable inputs
        // (product, quantity, and a 5-minute time bucket) so a retry of
        // *this* job -- same product, same quantity, same attempt window --
        // reuses the same key and Square recognises it as a repeat of the
        // same write rather than a new one. The time bucket exists only so
        // a *later*, unrelated push landing on the same product/quantity
        // combination (stock goes 10 -> 8 -> 10 within the same day) still
        // gets its own key instead of colliding with a stale one.
        $bucket = (int) floor(now()->timestamp / 300);
        $idempotencyKey = sha1("inventory:{$this->productId}:{$this->quantity}:{$bucket}");

        $change = InventoryApi::physicalCount(
            catalogObjectId: $mapping->square_object_id,
            quantity: $this->quantity,
            locationId: $getLocationId->handle(),
        );

        // SquareException bubbles out of here uncaught so the queue's
        // retry/backoff configuration above takes over -- markPushed() and
        // the audit event below only run once Square has actually accepted
        // the count.
        $client->inventory()->batchChangeInventory([$change], $idempotencyKey);

        // Inventory's echo-loop break is the reason field, not this hash
        // (ApplyInventoryCountFromSquare tags every inbound apply with
        // SQUARE_PULL, which PushStockToSquare refuses to push back
        // regardless of whether the quantity actually changed) -- the hash
        // here is purely for audit consistency with the catalog side.
        $mapping->markPushed(hash('sha256', json_encode($change)));

        $recordEvent->handle(
            type: 'square.inventory_pushed',
            description: "{$mapping->mappable?->title} stock pushed to Square ({$this->quantity})",
            subject: $mapping->mappable,
            metadata: [
                'square_object_id' => $mapping->square_object_id,
                'quantity' => $this->quantity,
            ],
            severity: 'info',
            direction: 'outbound',
        );
    }
}
