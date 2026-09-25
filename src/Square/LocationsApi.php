<?php

namespace Cultpantry\SquareSync\Square;

final class LocationsApi
{
    public function __construct(private readonly SquareClient $client) {}

    public function list(): SquareResponse
    {
        return $this->client->request('GET', '/v2/locations');
    }

    public function retrieve(string $locationId): SquareResponse
    {
        return $this->client->request('GET', '/v2/locations/'.rawurlencode($locationId));
    }
}
