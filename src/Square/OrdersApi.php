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

    public function create(array $order, string $idempotencyKey): SquareResponse
    {
        return $this->client->request('POST', '/v2/orders', [
            'idempotency_key' => $idempotencyKey,
            'order' => $order,
        ]);
    }

    public function retrieve(string $orderId): SquareResponse
    {
        return $this->client->request('GET', '/v2/orders/'.rawurlencode($orderId));
    }

    /**
     * Completes an order with the given payments -- none for a zero-total
     * order, which Square won't take a payment for.
     */
    public function pay(string $orderId, array $paymentIds, string $idempotencyKey): SquareResponse
    {
        return $this->client->request('POST', '/v2/orders/'.rawurlencode($orderId).'/pay', [
            'idempotency_key' => $idempotencyKey,
            'payment_ids' => $paymentIds,
        ]);
    }
}
