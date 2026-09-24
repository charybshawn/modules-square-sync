<?php

namespace Cultpantry\SquareSync\Square;

use Carbon\CarbonInterface;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

/**
 * Orders endpoints -- the source of Square sales records (as opposed to
 * stock, which comes from InventoryApi's change log).
 */
final class OrdersApi
{
    /**
     * Square's maximum page size for search.
     */
    private const PAGE_SIZE = 500;

    public function __construct(private readonly SquareClient $client) {}

    /**
     * POST /v2/orders/search, auto-paginating on cursor: every COMPLETED
     * order at $locationIds whose updated_at falls in [$updatedFrom,
     * $updatedTo), oldest first. Filtered and sorted on updated_at (not
     * closed_at) so an order that gains a refund later -- which bumps
     * updated_at -- falls inside a later incremental window too.
     */
    public function searchCompleted(array $locationIds, CarbonInterface $updatedFrom, ?CarbonInterface $updatedTo = null): LazyCollection
    {
        return LazyCollection::make(function () use ($locationIds, $updatedFrom, $updatedTo) {
            $correlationId = (string) Str::uuid();
            $cursor = null;

            do {
                $payload = array_filter([
                    'location_ids' => $locationIds,
                    'limit' => self::PAGE_SIZE,
                    'cursor' => $cursor,
                    'query' => [
                        'filter' => [
                            'state_filter' => ['states' => ['COMPLETED']],
                            'date_time_filter' => [
                                'updated_at' => array_filter([
                                    'start_at' => $updatedFrom->toIso8601String(),
                                    'end_at' => $updatedTo?->toIso8601String(),
                                ]),
                            ],
                        ],
                        'sort' => ['sort_field' => 'UPDATED_AT', 'sort_order' => 'ASC'],
                    ],
                ]);

                $response = $this->client->request('POST', '/v2/orders/search', $payload, $correlationId);

                foreach ($response->json('orders') ?? [] as $order) {
                    yield $order;
                }

                $cursor = $response->cursor();
            } while ($cursor !== null);
        });
    }
}
