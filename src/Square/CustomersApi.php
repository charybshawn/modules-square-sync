<?php

namespace Cultpantry\SquareSync\Square;

/**
 * Customer directory lookups, for attaching a customer to a recorded sale.
 */
final class CustomersApi
{
    /**
     * Square's per-request cap for bulk-retrieve.
     */
    private const MAX_IDS_PER_REQUEST = 100;

    public function __construct(private readonly SquareClient $client) {}

    /**
     * POST /v2/customers/bulk-retrieve, chunked at Square's cap.
     *
     * @param  array<int, string>  $customerIds
     * @return array<string, array> customer id => Square customer object;
     *                              ids Square couldn't return (deleted, merged) are simply absent
     */
    public function bulkRetrieve(array $customerIds): array
    {
        $customers = [];

        foreach (array_chunk(array_values(array_unique($customerIds)), self::MAX_IDS_PER_REQUEST) as $chunk) {
            $response = $this->client->request('POST', '/v2/customers/bulk-retrieve', ['customer_ids' => $chunk]);

            foreach ($response->json('responses') ?? [] as $id => $result) {
                if (isset($result['customer'])) {
                    $customers[$id] = $result['customer'];
                }
            }
        }

        return $customers;
    }
}
