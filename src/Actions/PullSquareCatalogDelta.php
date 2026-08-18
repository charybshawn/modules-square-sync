<?php

namespace Cultpantry\SquareSync\Actions;

use App\Actions\GetSiteSetting;
use App\Actions\RecordEvent;
use App\Actions\UpdateSiteSetting;
use App\Models\Event;
use App\Models\Product;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Square\SquareClient;
use Illuminate\Support\Carbon;

/**
 * Runs a delta pull of catalog objects changed since the last successful
 * pull, in response to an inbound catalog.version.updated webhook.
 *
 * Square's catalog.version.updated payload carries only a timestamp -- not
 * what changed -- so the only way to find out is to ask: search-catalog-
 * objects with begin_time set to a watermark this class owns. The
 * watermark is a site setting (there's no dedicated column for it anywhere
 * in this module's schema) rather than, say, "the created_at of the last
 * processed webhook event", because a delta pull can miss objects that
 * changed between two webhook deliveries if this class only ever looked as
 * far back as the previous webhook's timestamp -- the watermark instead
 * tracks how far this class has actually walked the catalog.
 */
class PullSquareCatalogDelta
{
    /**
     * Site setting key. Namespaced like the costing module's
     * 'modules.cultpantry/costing.enabled' convention, but for a watermark
     * rather than a feature flag.
     */
    public const WATERMARK_KEY = 'square_sync.catalog_delta_watermark';

    /**
     * Lookback used only on the very first delta pull this module ever
     * runs (no watermark stored yet). square:pull-catalog is the intended
     * way to seed a full initial import; this is just a safety net so an
     * early webhook arriving before that command has run doesn't have to
     * search from the beginning of time.
     */
    private const FALLBACK_LOOKBACK_HOURS = 24;

    public function __construct(
        private readonly SquareClient $client,
        private readonly GetSiteSetting $getSetting,
        private readonly UpdateSiteSetting $updateSetting,
        private readonly RecordEvent $recordEvent,
    ) {}

    /**
     * @return array{processed: int, echo_skipped: int, soft_deleted: int, updated: int}
     */
    public function handle(?string $correlationId = null, ?Event $parentEvent = null): array
    {
        $storedWatermark = $this->getSetting->handle(self::WATERMARK_KEY);

        $beginTime = $storedWatermark
            ? Carbon::parse($storedWatermark)
            : now()->subHours(self::FALLBACK_LOOKBACK_HOURS);

        // Captured before the pull runs and stored unconditionally as the
        // next watermark, rather than derived from anything in Square's
        // response -- an object that changes on Square's side while this
        // loop is still running would otherwise fall into the gap between
        // "the last object this pull saw" and "when this pull finished",
        // and never be picked up by the next delta pull.
        $pullStartedAt = now();

        $tally = ['processed' => 0, 'echo_skipped' => 0, 'soft_deleted' => 0, 'updated' => 0];

        foreach ($this->client->catalog()->searchObjects(['begin_time' => $beginTime->toIso8601String()]) as $object) {
            $tally['processed']++;

            $outcome = $this->applyObject($object, $correlationId, $parentEvent);

            if (array_key_exists($outcome, $tally)) {
                $tally[$outcome]++;
            }
        }

        $this->updateSetting->handle(self::WATERMARK_KEY, $pullStartedAt->toIso8601String());

        $this->recordEvent->handle(
            type: 'square.catalog_pulled',
            description: "Square catalog delta pulled ({$tally['processed']} object(s))",
            metadata: [
                'begin_time' => $beginTime->toIso8601String(),
                ...$tally,
            ],
            severity: 'info',
            direction: 'inbound',
            correlationId: $correlationId,
            parentEvent: $parentEvent,
        );

        return $tally;
    }

    /**
     * @return string One of 'echo_skipped', 'soft_deleted', 'updated', or
     *                'unmapped' (an object this app has no mapping for --
     *                square:pull-catalog's job, not a delta pull's).
     */
    private function applyObject(array $object, ?string $correlationId, ?Event $parentEvent): string
    {
        $squareObjectId = $object['id'] ?? null;

        if (blank($squareObjectId)) {
            return 'unmapped';
        }

        $mapping = SquareObjectMapping::forSquareId($squareObjectId)->first();

        if (! $mapping) {
            return 'unmapped';
        }

        // Square's catalog.version.updated fires on our own outbound writes
        // too, so without this check every push this app makes would
        // trigger a pull that appears to "confirm" the very change we just
        // sent -- harmless today, but it pollutes the audit log and would
        // become actively wrong the moment any pulled object starts
        // overwriting local fields.
        if ($this->isEcho($object, $mapping)) {
            return 'echo_skipped';
        }

        if (($object['is_deleted'] ?? false) === true) {
            return $this->softDeleteMappedProduct($mapping, $squareObjectId, $correlationId, $parentEvent);
        }

        // A genuine, independent change. This module doesn't sync catalog
        // field data (name, price, etc.) back from Square into Product --
        // only inventory counts and deletions are pulled -- so all there is
        // to do here is keep the mapping's version current, which prevents
        // it from being mistaken for stale (and blocking) the next push.
        $mapping->markPulled($object['version'] ?? null);

        return 'updated';
    }

    private function isEcho(array $object, SquareObjectMapping $mapping): bool
    {
        $version = $object['version'] ?? null;

        if ($version !== null && $mapping->square_version !== null && (int) $version === (int) $mapping->square_version) {
            return true;
        }

        return $mapping->last_pushed_hash !== null
            && $this->hashObject($object) === $mapping->last_pushed_hash;
    }

    /**
     * Must match whatever the outbound push side hashes when it calls
     * markPushed() -- that pairing is owned by WP4/WP6, not this class, so
     * this is only correct once those hash the same canonical
     * representation of the object.
     */
    private function hashObject(array $object): string
    {
        return hash('sha256', json_encode($object));
    }

    private function softDeleteMappedProduct(
        SquareObjectMapping $mapping,
        string $squareObjectId,
        ?string $correlationId,
        ?Event $parentEvent,
    ): string {
        $product = $mapping->mappable;

        if (! $product instanceof Product) {
            return 'updated';
        }

        if (! $product->trashed()) {
            $product->delete();
        }

        // The Square object is gone, so this mapping no longer points at
        // anything live -- unlink() (a soft delete) rather than
        // markConflict(), since a deletion isn't a divergent edit to
        // reconcile, it's the end of this pairing. Soft delete keeps the
        // sync history queryable, matching the model's existing contract.
        $mapping->unlink();

        $this->recordEvent->handle(
            type: 'square.product_soft_deleted',
            description: "{$product->title} soft-deleted -- Square object archived or removed",
            subject: $product,
            metadata: [
                'square_object_id' => $squareObjectId,
            ],
            severity: 'warning',
            direction: 'inbound',
            correlationId: $correlationId,
            parentEvent: $parentEvent,
        );

        return 'soft_deleted';
    }
}
