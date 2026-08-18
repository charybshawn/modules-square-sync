<?php

namespace Cultpantry\SquareSync\Square;

use Carbon\CarbonInterface;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

/**
 * Inventory endpoints. Square's batch-create endpoint caps at 100 changes
 * per request, which callers doing a full stock reconciliation will
 * routinely blow past, so batchChangeInventory() chunks internally rather
 * than making every caller remember the limit.
 */
final class InventoryApi
{
    private const MAX_CHANGES_PER_REQUEST = 100;

    public function __construct(private readonly SquareClient $client) {}

    /**
     * POST /v2/inventory/counts/batch-retrieve, auto-paginating on cursor.
     */
    public function batchRetrieveCounts(array $catalogObjectIds, array $locationIds): LazyCollection
    {
        return LazyCollection::make(function () use ($catalogObjectIds, $locationIds) {
            $correlationId = (string) Str::uuid();
            $cursor = null;

            do {
                $payload = array_filter([
                    'catalog_object_ids' => $catalogObjectIds,
                    'location_ids' => $locationIds,
                    'cursor' => $cursor,
                ]);

                $response = $this->client->request('POST', '/v2/inventory/counts/batch-retrieve', $payload, $correlationId);

                foreach ($response->json('counts') ?? [] as $count) {
                    yield $count;
                }

                $cursor = $response->cursor();
            } while ($cursor !== null);
        });
    }

    /**
     * POST /v2/inventory/changes/batch-create, chunked at 100 changes per
     * request. Each chunk gets its own idempotency key derived from the
     * caller's key plus its index -- if a sync run is retried after a
     * partial failure, an already-applied chunk is recognised as a repeat
     * rather than double-applied, while a chunk that never went through
     * still gets a fresh attempt under its own key.
     */
    public function batchChangeInventory(array $changes, string $idempotencyKey, bool $ignoreUnchangedCounts = true): SquareResponse
    {
        $chunks = array_chunk($changes, self::MAX_CHANGES_PER_REQUEST);
        $correlationId = (string) Str::uuid();

        $responses = [];

        foreach ($chunks as $index => $chunk) {
            $responses[] = $this->client->request('POST', '/v2/inventory/changes/batch-create', [
                'idempotency_key' => count($chunks) > 1 ? "{$idempotencyKey}:{$index}" : $idempotencyKey,
                'changes' => $chunk,
                'ignore_unchanged_counts' => $ignoreUnchangedCounts,
            ], $correlationId);
        }

        return SquareResponse::aggregate($responses);
    }

    /**
     * Builds a single PHYSICAL_COUNT change entry. Note that Square wants
     * `quantity` as a string, not a number, despite it always being a
     * whole count -- passing an int is a common integration bug that Square
     * silently accepts (as "0") rather than rejecting.
     */
    public static function physicalCount(
        string $catalogObjectId,
        int $quantity,
        string $locationId,
        ?CarbonInterface $occurredAt = null,
    ): array {
        return [
            'type' => 'PHYSICAL_COUNT',
            'physical_count' => [
                'catalog_object_id' => $catalogObjectId,
                'state' => 'IN_STOCK',
                'location_id' => $locationId,
                'quantity' => (string) $quantity,
                'occurred_at' => ($occurredAt ?? now())->toIso8601String(),
            ],
        ];
    }
}
