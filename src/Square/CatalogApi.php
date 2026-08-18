<?php

namespace Cultpantry\SquareSync\Square;

use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

/**
 * Catalog endpoints. The two listing methods (listItems, searchObjects)
 * return LazyCollections so a full-catalog pull never has to hold every
 * page in memory at once -- callers can pipe straight into a queued job
 * that upserts one object at a time.
 */
final class CatalogApi
{
    public function __construct(private readonly SquareClient $client) {}

    /**
     * GET /v2/catalog/list, auto-paginating on the cursor query param.
     * Suitable for a full sync; use searchObjects() for a delta pull.
     */
    public function listItems(array $types = ['ITEM', 'ITEM_VARIATION']): LazyCollection
    {
        return $this->paginate('GET', '/v2/catalog/list', array_filter([
            'types' => implode(',', $types),
        ]), 'objects');
    }

    /**
     * POST /v2/catalog/search-catalog-objects, auto-paginating on the
     * cursor body field. Callers pass e.g. ['begin_time' => ...] for a
     * delta pull since the last sync.
     */
    public function searchObjects(array $query): LazyCollection
    {
        return $this->paginate('POST', '/v2/catalog/search-catalog-objects', $query, 'objects');
    }

    public function retrieveObject(string $id): SquareResponse
    {
        return $this->client->request('GET', "/v2/catalog/object/{$id}");
    }

    /**
     * POST /v2/catalog/batch-upsert-catalog-objects. Square requires the
     * objects to be wrapped in a batches array even for a single batch --
     * WP1 only ever sends one, so that's all this builds.
     */
    public function batchUpsert(array $objects, string $idempotencyKey): SquareResponse
    {
        return $this->client->request('POST', '/v2/catalog/batch-upsert-catalog-objects', [
            'idempotency_key' => $idempotencyKey,
            'batches' => [
                ['objects' => $objects],
            ],
        ]);
    }

    public function deleteObject(string $id): SquareResponse
    {
        return $this->client->request('DELETE', "/v2/catalog/object/{$id}");
    }

    /**
     * Shared pagination loop for both listing endpoints. Works for GET and
     * POST alike because SquareClient::request() already routes the
     * 'cursor' payload key to a query param or JSON field depending on
     * $method -- this only has to know when to stop.
     *
     * One correlation ID per call (not per page) so a full paginated pull
     * shows up as a single correlated group of events in the audit log.
     */
    private function paginate(string $method, string $path, array $payload, string $key): LazyCollection
    {
        return LazyCollection::make(function () use ($method, $path, $payload, $key) {
            $correlationId = (string) Str::uuid();
            $cursor = null;

            do {
                $params = $cursor === null ? $payload : [...$payload, 'cursor' => $cursor];

                $response = $this->client->request($method, $path, $params, $correlationId);

                foreach ($response->json($key) ?? [] as $object) {
                    yield $object;
                }

                $cursor = $response->cursor();
            } while ($cursor !== null);
        });
    }
}
