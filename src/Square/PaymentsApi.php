<?php

namespace Cultpantry\SquareSync\Square;

final class PaymentsApi
{
    public function __construct(private readonly SquareClient $client) {}

    public function create(array $payment, string $idempotencyKey): SquareResponse
    {
        return $this->client->request('POST', '/v2/payments', [
            'idempotency_key' => $idempotencyKey,
            ...$payment,
        ]);
    }
}
