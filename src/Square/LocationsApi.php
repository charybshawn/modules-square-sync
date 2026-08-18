<?php

namespace Cultpantry\SquareSync\Square;

/**
 * Locations endpoint. Just the one method -- WP1 only needs this to
 * validate config('square-sync.location_id') against what Square actually
 * has on file.
 */
final class LocationsApi
{
    public function __construct(private readonly SquareClient $client) {}

    public function list(): SquareResponse
    {
        return $this->client->request('GET', '/v2/locations');
    }
}
