<?php

namespace Cultpantry\SquareSync\Jobs;

use App\Actions\RecordEvent;
use App\Models\Product;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Square\SquareClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pushes a product's title/description/price to Square as a CatalogItem
 * (with a single embedded ITEM_VARIATION carrying the price -- Square has
 * no price field on the item itself). Dispatched by ProductObserver
 * whenever one of those three fields changes.
 *
 * Constructor takes the product id, not the model, for the same
 * re-fetch-a-stale-value reason documented on PushInventoryCountJob --
 * handle() re-reads the product fresh from the database when the worker
 * actually runs, rather than trusting a snapshot taken at dispatch time.
 *
 * Real product variants aren't modelled yet (see SquareObjectMapping's
 * docblock), so the embedded variation's own Square id is derived
 * deterministically from the parent item's rather than tracked in a
 * separate mapping row -- sufficient for "one product, one price" today,
 * and revisited if/when this app grows real variants.
 */
class PushCatalogObjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(public readonly int $productId) {}

    public function handle(SquareClient $client, RecordEvent $recordEvent): void
    {
        $product = Product::find($this->productId);

        // Hard-deleted (or otherwise gone) between dispatch and execution
        // -- nothing left to push. Soft-deleted products are handled by
        // ArchiveSquareCatalogObjectJob instead, not this one.
        if (! $product) {
            return;
        }

        $mapping = SquareObjectMapping::query()
            ->where('mappable_type', Product::class)
            ->where('mappable_id', $this->productId)
            ->first();

        $isCreate = $mapping === null;

        $object = $this->buildCatalogObject($product, $mapping, $isCreate);

        // Hashed before the create path's id_mappings response is known,
        // so this is a hash of exactly what we sent -- see
        // PullSquareCatalogDelta::hashObject()'s docblock for the inbound
        // half of this pairing. A byte-for-byte match against a future
        // pulled object isn't guaranteed (Square appends fields like
        // updated_at/is_deleted we never sent), so square_version is the
        // primary echo-loop break on the catalog side; this hash is the
        // secondary one for whenever a version comparison isn't possible.
        $hash = hash('sha256', json_encode($object));
        $idempotencyKey = sha1("catalog:{$this->productId}:{$hash}");

        // SquareException bubbles out uncaught so the queue's retry/backoff
        // configuration takes over.
        $response = $client->catalog()->batchUpsert([$object], $idempotencyKey);

        $pushedObject = $response->json('objects.0') ?? [];
        $version = isset($pushedObject['version']) ? (int) $pushedObject['version'] : null;

        if ($isCreate) {
            $realId = $pushedObject['id'] ?? null;

            // Square accepted the write but, unexpectedly, didn't echo back
            // a real id for it -- nothing to link a mapping against, so
            // there's nothing safe to do here. Should not happen in
            // practice; the request/response pair is still in the events
            // audit log via SquareClient for investigation.
            if (blank($realId)) {
                return;
            }

            $mapping = SquareObjectMapping::linkTo($product, $realId, 'ITEM');
        }

        // The catalog echo-loop break: catalog.version.updated fires on our
        // own writes too, and PullSquareCatalogDelta::isEcho() compares an
        // inbound object's version/hash against square_version/
        // last_pushed_hash to recognise "this is just confirming the push
        // we just made" rather than an independent Square-side edit.
        $mapping->markPushed($hash, $version);

        $recordEvent->handle(
            type: 'square.catalog_pushed',
            description: "{$product->title} catalog pushed to Square",
            subject: $product,
            metadata: [
                'square_object_id' => $mapping->square_object_id,
                'title' => $product->title,
                'price' => $product->price,
                'created' => $isCreate,
            ],
            severity: 'info',
            direction: 'outbound',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCatalogObject(Product $product, ?SquareObjectMapping $mapping, bool $isCreate): array
    {
        $itemId = $isCreate ? '#temp_id' : $mapping->square_object_id;
        $variationId = $isCreate ? '#temp_id_variation' : "{$itemId}#regular";

        $object = [
            'type' => 'ITEM',
            'id' => $itemId,
            'item_data' => [
                'name' => $product->title,
                'description' => $product->description,
                'variations' => [[
                    'type' => 'ITEM_VARIATION',
                    'id' => $variationId,
                    'item_variation_data' => [
                        'item_id' => $itemId,
                        'name' => 'Regular',
                        'pricing_type' => 'FIXED_PRICING',
                        'price_money' => [
                            // Square wants integer cents, not a decimal.
                            'amount' => (int) round(((float) $product->price) * 100),
                            'currency' => $product->currency ?? 'CAD',
                        ],
                    ],
                ]],
            ],
        ];

        // Square rejects an update made against a stale version -- only
        // send one on the update path; a create has no version yet.
        if (! $isCreate && $mapping->square_version !== null) {
            $object['version'] = $mapping->square_version;
        }

        return $object;
    }
}
