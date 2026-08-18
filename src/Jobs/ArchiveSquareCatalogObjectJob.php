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
 * Archives, or un-archives, a product's Square catalog item -- dispatched
 * by ProductObserver on soft-delete ($archived: true) and restore
 * ($archived: false).
 *
 * Deliberately never calls CatalogApi::deleteObject(). Square's DELETE
 * hard-removes a catalog object with no undo, which would permanently
 * sever the mapping the moment a product is soft-deleted -- exactly the
 * kind of operation this app's own soft-delete convention exists to avoid.
 * item_data.is_archived is Square's reversible equivalent: hidden from
 * sale, but the object (and its id/version history) survives, so a
 * restored product can be un-archived back onto Square with the same
 * mapping row instead of starting over as a brand new catalog item.
 */
class ArchiveSquareCatalogObjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $productId,
        public readonly bool $archived,
    ) {}

    public function handle(SquareClient $client, RecordEvent $recordEvent): void
    {
        $mapping = SquareObjectMapping::query()
            ->where('mappable_type', Product::class)
            ->where('mappable_id', $this->productId)
            ->first();

        // Never synced to Square in the first place -- nothing to archive
        // or restore there.
        if (! $mapping) {
            return;
        }

        // withTrashed(), not mapping->mappable() -- on the archive path the
        // product is already soft-deleted by the time this job runs
        // (dispatched ->afterCommit() from ProductObserver::deleted()), and
        // MorphTo's default query excludes trashed rows, which would
        // otherwise resolve to null here and drop the product off the
        // audit event below.
        $product = Product::withTrashed()->find($this->productId);

        $object = array_filter([
            'type' => 'ITEM',
            'id' => $mapping->square_object_id,
            'item_data' => ['is_archived' => $this->archived],
            // Square rejects an update made against a stale version.
            'version' => $mapping->square_version,
        ], fn ($value) => $value !== null);

        $hash = hash('sha256', json_encode($object));
        $action = $this->archived ? 'archive' : 'unarchive';
        $idempotencyKey = sha1("{$action}:{$this->productId}:{$hash}");

        // SquareException bubbles out uncaught so the queue's retry/backoff
        // configuration takes over.
        $response = $client->catalog()->batchUpsert([$object], $idempotencyKey);

        $version = $response->json('objects.0.version');

        $mapping->markPushed($hash, $version !== null ? (int) $version : null);

        // There's no dedicated "un-archived" audit type in Event::TYPES --
        // square.product_soft_deleted is used only for the archive
        // direction (it mirrors what actually happened locally: the
        // product was soft-deleted); un-archiving is recorded as an
        // ordinary catalog push, since that's what it is from Square's side.
        $recordEvent->handle(
            type: $this->archived ? 'square.product_soft_deleted' : 'square.catalog_pushed',
            description: $this->archived
                ? "{$product?->title} soft-deleted -- archived on Square"
                : "{$product?->title} restored -- un-archived on Square",
            subject: $product,
            metadata: [
                'square_object_id' => $mapping->square_object_id,
                'archived' => $this->archived,
            ],
            severity: $this->archived ? 'warning' : 'info',
            direction: 'outbound',
        );
    }
}
