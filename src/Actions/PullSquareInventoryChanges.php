<?php

namespace Cultpantry\SquareSync\Actions;

use App\Actions\GetSiteSetting;
use App\Actions\UpdateSiteSetting;
use Cultpantry\SquareSync\Contracts\AuditLog;
use Cultpantry\SquareSync\Contracts\LocalInventory;
use Cultpantry\SquareSync\Models\SquareInventoryChange;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Square\SquareClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Inbound half of the inventory sync: applies Square *sales* (and the
 * refunds/returns that reverse them) to local stock -- and nothing else.
 *
 * Local stock is the source of truth. Square's inventory.count.updated
 * webhook only says "this item is now N", which can't distinguish a sale
 * from someone recounting in Square's dashboard, so this reads Square's
 * inventory change log instead, where every movement carries its from/to
 * states: a sale is an ADJUSTMENT out of IN_STOCK into a sale state, a
 * restock is one back into IN_STOCK. Recounts (PHYSICAL_COUNT), waste,
 * damage, and our own pushes never match, so they never touch local
 * stock -- EnforceLocalInventoryOnSquare then overwrites them on Square.
 *
 * Exactly-once: every applied change is claimed in square_inventory_changes
 * by Square's change id, in the same transaction as the stock write.
 * That's what makes it safe for each run to re-read an overlapping window
 * (the change log is eventually consistent -- a change can surface a
 * little after the webhook that announced it) and for a retried webhook to
 * run this again.
 */
class PullSquareInventoryChanges
{
    /**
     * Site setting key -- same convention as
     * PullSquareCatalogDelta::WATERMARK_KEY. Seeded to the deploy time by
     * this module's square_inventory_changes migration, so the first run
     * after the switch from the old count-copying sync starts exactly
     * where that sync stopped instead of replaying sales it already
     * applied.
     */
    public const WATERMARK_KEY = 'square_sync.inventory_changes_watermark';

    /**
     * How far behind the stored watermark each run starts reading. Covers
     * the change log's eventual consistency; the ledger dedupes the
     * overlap.
     */
    private const OVERLAP_MINUTES = 10;

    private const LOCK_SECONDS = 120;

    /**
     * States on the far side of IN_STOCK that mean "a customer bought (or
     * returned) this": units moving between one of these and IN_STOCK are
     * a sale or its reversal. RESERVED_FOR_SALE is where Square parks
     * units for an open online/invoice order -- they leave IN_STOCK at
     * reservation time, so that's the moment local stock drops, and the
     * later RESERVED_FOR_SALE -> SOLD step never touches IN_STOCK and is
     * ignored. A reservation released back (cancelled order) returns them.
     */
    private const SALE_STATES = ['SOLD', 'RESERVED_FOR_SALE', 'RETURNED_BY_CUSTOMER'];

    public function __construct(
        private readonly SquareClient $client,
        private readonly GetSiteSetting $getSetting,
        private readonly UpdateSiteSetting $updateSetting,
        private readonly GetSquareLocationId $getLocationId,
        private readonly LocalInventory $inventory,
        private readonly AuditLog $auditLog,
    ) {}

    /**
     * @return array{sold: int, restocked: int}
     */
    public function handle(?string $correlationId = null, ?int $parentRef = null): array
    {
        $locationId = $this->getLocationId->handle();

        if (blank($locationId)) {
            return ['sold' => 0, 'restocked' => 0];
        }

        // Serialised: a webhook burst (several sales in a row) would
        // otherwise run overlapping pulls that each advance the watermark
        // past changes the other hasn't finished applying. block() throws
        // LockTimeoutException if another run holds it too long -- the
        // webhook then 500s and Square retries, which is what we want.
        return Cache::lock('square-sync:inventory-changes', self::LOCK_SECONDS)
            ->block(30, fn () => $this->pull($locationId, $correlationId, $parentRef));
    }

    /**
     * @return array{sold: int, restocked: int}
     */
    private function pull(string $locationId, ?string $correlationId, ?int $parentRef): array
    {
        $storedWatermark = $this->getSetting->handle(self::WATERMARK_KEY);

        // Captured before the pull runs, following PullSquareCatalogDelta:
        // a change landing while this loop runs must fall inside the next
        // run's window, not the gap between the two.
        $pullStartedAt = now();

        // No watermark only if the migration's seed was lost -- then start
        // from "now" rather than the beginning of time, since every earlier
        // sale is already reflected in local stock.
        $since = ($storedWatermark ? Carbon::parse($storedWatermark) : $pullStartedAt->copy())
            ->subMinutes(self::OVERLAP_MINUTES);

        $tally = ['sold' => 0, 'restocked' => 0];

        foreach ($this->client->inventory()->batchRetrieveChanges([$locationId], ['ADJUSTMENT'], $since) as $change) {
            $kind = $this->applyChange($change['adjustment'] ?? [], $locationId, $correlationId);

            if ($kind === 'sale') {
                $tally['sold']++;
            } elseif ($kind === 'restock') {
                $tally['restocked']++;
            }
        }

        $this->updateSetting->handle(self::WATERMARK_KEY, $pullStartedAt->toIso8601String());

        if ($tally['sold'] + $tally['restocked'] > 0) {
            $this->auditLog->record(
                type: 'square.inventory_pulled',
                description: "Square sales applied to local stock ({$tally['sold']} sale(s), {$tally['restocked']} restock(s))",
                metadata: ['since' => $since->toIso8601String(), ...$tally],
                severity: 'info',
                direction: 'inbound',
                correlationId: $correlationId,
                parentRef: $parentRef,
            );
        }

        return $tally;
    }

    /**
     * @return string|null 'sale', 'restock', or null when the change was
     *                     not applied (not a sale movement, unmapped, or already applied)
     */
    private function applyChange(array $adjustment, string $locationId, ?string $correlationId): ?string
    {
        $classified = $this->classify($adjustment);

        if ($classified === null || ($adjustment['location_id'] ?? $locationId) !== $locationId) {
            return null;
        }

        [$kind, $quantity] = $classified;

        $mapping = SquareObjectMapping::forSquareId($adjustment['catalog_object_id'] ?? '')->linked()->first();

        if (! $mapping || blank($adjustment['id'] ?? null) || $quantity === 0) {
            return null;
        }

        $metadata = [
            'square_change_id' => $adjustment['id'],
            'square_object_id' => $mapping->square_object_id,
            'square_transaction_id' => $adjustment['transaction_id'] ?? null,
            'square_refund_id' => $adjustment['refund_id'] ?? null,
            'correlation_id' => $correlationId,
        ];

        return DB::transaction(function () use ($adjustment, $mapping, $kind, $quantity, $metadata) {
            $claimed = SquareInventoryChange::claim([
                'square_change_id' => $adjustment['id'],
                'square_object_id' => $mapping->square_object_id,
                'local_item_id' => $mapping->mappable_id,
                'kind' => $kind,
                'quantity' => $kind === 'sale' ? -$quantity : $quantity,
                'from_state' => $adjustment['from_state'] ?? null,
                'to_state' => $adjustment['to_state'] ?? null,
                'transaction_id' => $adjustment['transaction_id'] ?? null,
                'refund_id' => $adjustment['refund_id'] ?? null,
                'occurred_at' => isset($adjustment['occurred_at']) ? Carbon::parse($adjustment['occurred_at']) : null,
            ]);

            if ($claimed === null) {
                return null;
            }

            if ($kind === 'sale') {
                $this->inventory->applySquareSale($mapping->mappable_id, $quantity, $metadata);
            } else {
                $this->inventory->applySquareRestock($mapping->mappable_id, $quantity, $metadata);
            }

            $mapping->markPulled();

            return $kind;
        });
    }

    /**
     * @return array{0: 'sale'|'restock', 1: int}|null
     */
    private function classify(array $adjustment): ?array
    {
        $from = $adjustment['from_state'] ?? null;
        $to = $adjustment['to_state'] ?? null;

        // Square sends quantity as a decimal string ("1", "2.000"); local
        // stock is whole units.
        $quantity = (int) round((float) ($adjustment['quantity'] ?? 0));

        if ($from === 'IN_STOCK' && in_array($to, self::SALE_STATES, true)) {
            return ['sale', $quantity];
        }

        // A refund with restock may come from a sale state or, depending
        // on how the refund was taken, from NONE -- the refund_id is what
        // makes it a sale reversal rather than a manual stock-in.
        if ($to === 'IN_STOCK' && (in_array($from, self::SALE_STATES, true) || filled($adjustment['refund_id'] ?? null))) {
            return ['restock', $quantity];
        }

        return null;
    }
}
