<?php

use App\Models\Product;
use Cultpantry\SquareSync\Contracts\LocalCatalog;
use Cultpantry\SquareSync\Contracts\Null\NullLocalCatalog;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Models\SquareWebhookEvent;

describe('SquareObjectMapping::linkTo', function () {
    it('is idempotent -- calling it twice with the same square id yields one row', function () {
        $product = Product::factory()->create();

        $first = SquareObjectMapping::linkTo($product->id, 'SQ_ITEM_ABC123', 'ITEM');
        $second = SquareObjectMapping::linkTo($product->id, 'SQ_ITEM_ABC123', 'ITEM');

        expect($first->id)->toBe($second->id);
        expect(SquareObjectMapping::where('square_object_id', 'SQ_ITEM_ABC123')->count())->toBe(1);
    });

    it('revives a soft-deleted mapping instead of colliding on the unique square_object_id', function () {
        $product = Product::factory()->create();

        $mapping = SquareObjectMapping::linkTo($product->id, 'SQ_ITEM_REVIVE', 'ITEM');
        $mapping->unlink();

        expect(SquareObjectMapping::where('square_object_id', 'SQ_ITEM_REVIVE')->exists())->toBeFalse();

        $relinked = SquareObjectMapping::linkTo($product->id, 'SQ_ITEM_REVIVE', 'ITEM');

        expect($relinked->id)->toBe($mapping->id);
        expect($relinked->trashed())->toBeFalse();
        expect(SquareObjectMapping::withTrashed()->where('square_object_id', 'SQ_ITEM_REVIVE')->count())->toBe(1);
    });
});

describe('SquareWebhookEvent::claim', function () {
    it('returns the row on first call and null on a duplicate square_event_id', function () {
        $payload = ['type' => 'catalog.version.updated'];

        $first = SquareWebhookEvent::claim('evt_123', 'catalog.version.updated', $payload);
        $duplicate = SquareWebhookEvent::claim('evt_123', 'catalog.version.updated', $payload);

        expect($first)->not->toBeNull();
        expect($duplicate)->toBeNull();
        expect(SquareWebhookEvent::where('square_event_id', 'evt_123')->count())->toBe(1);
    });

    it('stores the payload and defaults status to received', function () {
        $event = SquareWebhookEvent::claim('evt_456', 'inventory.count.updated', ['foo' => 'bar']);

        expect($event->status)->toBe('received');
        expect($event->payload)->toBe(['foo' => 'bar']);
    });
});

describe('sync_status scopes', function () {
    it('excludes soft-deleted mappings from the linked() scope', function () {
        $product = Product::factory()->create();

        $mapping = SquareObjectMapping::factory()->forItem($product->id)->create([
            'sync_status' => 'linked',
        ]);

        expect(SquareObjectMapping::linked()->count())->toBe(1);

        $mapping->delete();

        expect(SquareObjectMapping::linked()->count())->toBe(0);
        // Still there under the hood -- unlink() is a soft delete so
        // history survives, it's just excluded from the active scope.
        expect(SquareObjectMapping::withTrashed()->linked()->count())->toBe(1);
    });
});

describe('SquareObjectMapping::markPushed', function () {
    it('sets the hash, version, and status', function () {
        $mapping = SquareObjectMapping::factory()->create([
            'sync_status' => 'pending',
            'last_pushed_hash' => null,
            'square_version' => null,
        ]);

        $mapping->markPushed('abc-hash', 42);

        $mapping->refresh();

        expect($mapping->last_pushed_hash)->toBe('abc-hash');
        expect($mapping->square_version)->toBe(42);
        expect($mapping->sync_status)->toBe('linked');
        expect($mapping->last_pushed_at)->not->toBeNull();
    });

    it('keeps the existing version when none is passed', function () {
        $mapping = SquareObjectMapping::factory()->create(['square_version' => 7]);

        $mapping->markPushed('a-new-hash');

        expect($mapping->fresh()->square_version)->toBe(7);
    });
});

describe('local item resolution (via the LocalCatalog contract)', function () {
    it('resolves back to the Product it maps', function () {
        $product = Product::factory()->create();

        $mapping = SquareObjectMapping::linkTo($product->id, 'SQ_ITEM_PRODUCT', 'ITEM');

        expect($mapping->mappable_type)->toBe(Product::class);
        expect($mapping->fresh()->localItem()->id)->toBe($product->id);
        expect($mapping->fresh()->localItem()->title)->toBe($product->title);
    });

    it('still resolves an archived product, so its last-known title shows', function () {
        $product = Product::factory()->create();
        $mapping = SquareObjectMapping::linkTo($product->id, 'SQ_ITEM_ARCHIVED', 'ITEM');

        $product->delete();

        expect($mapping->fresh()->localItem()?->trashed)->toBeTrue();
    });

    it('boots with the null contract defaults when a host binds nothing', function () {
        app()->forgetInstance(LocalCatalog::class);
        app()->bind(LocalCatalog::class, NullLocalCatalog::class);

        $mapping = SquareObjectMapping::factory()->create();

        expect($mapping->localItem())->toBeNull();
    });
});
