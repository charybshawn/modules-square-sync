<?php

namespace Cultpantry\SquareSync\Actions;

use Cultpantry\SquareSync\Square\SquareClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lists the Square locations available to whatever account
 * SQUARE_ACCESS_TOKEN belongs to, so the admin UI can offer a dropdown
 * instead of asking someone to hand-copy a location id out of Square's
 * dashboard -- that's the whole point of making the location configurable
 * from the admin UI in the first place.
 *
 * Cached for 5 minutes: FetchSquareSyncData calls this on every load of the
 * admin Square Sync page, and there's no reason to hit Square's API that
 * often for data that changes approximately never.
 */
class FetchSquareLocations
{
    private const CACHE_KEY = 'square_sync.locations';

    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(private readonly SquareClient $client) {}

    /**
     * @return array<int, array{id: string, name: string, status: string, address: ?string}>
     */
    public function handle(): array
    {
        // Never call Square without a token -- SquareClient::request()
        // would just fail anyway, but this also avoids logging a noisy
        // "Square unreachable" warning on a page load where Square was
        // never going to be reachable to begin with.
        if (blank(config('square-sync.access_token'))) {
            return [];
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $locations = $this->fetch();

        // Deliberately not Cache::remember(): that caches whatever the
        // callback returns, and fetch() returns [] on failure. Caching a
        // failure means one bad call -- a page load before the token was
        // configured, or a momentary Square outage -- keeps the admin page
        // showing "locations could not be loaded" for the full TTL even
        // after the underlying problem is fixed, with no way to clear it
        // from the UI. Only a non-empty success is worth remembering; a
        // genuinely location-less account just re-asks, which is harmless
        // since Square accounts always have at least one.
        if ($locations !== []) {
            Cache::put(self::CACHE_KEY, $locations, self::CACHE_TTL);
        }

        return $locations;
    }

    /**
     * @return array<int, array{id: string, name: string, status: string, address: ?string}>
     */
    private function fetch(): array
    {
        try {
            $response = $this->client->locations()->list();
        } catch (Throwable $e) {
            // Catches SquareException (Square rejected the request -- e.g.
            // a bad token) as well as anything else that can go wrong on
            // the wire (timeout, DNS, etc.) -- SquareException already
            // extends Throwable, so one catch covers both cases the admin
            // page must survive. The page rendering because Square happens
            // to be unreachable would be a worse trade than an empty list.
            Log::warning('Failed to fetch Square locations', ['exception' => $e->getMessage()]);

            return [];
        }

        return collect($response->json('locations') ?? [])
            ->map(fn (array $location): array => [
                'id' => $location['id'],
                'name' => $location['name'] ?? $location['id'],
                'status' => $location['status'] ?? 'UNKNOWN',
                'address' => $this->formatAddress($location['address'] ?? null),
            ])
            ->all();
    }

    /**
     * A short, human-readable line -- not the full structured address --
     * since this only ever needs to help an admin tell two locations with
     * similar names apart in a dropdown.
     */
    private function formatAddress(?array $address): ?string
    {
        if (blank($address)) {
            return null;
        }

        $parts = array_filter([
            $address['address_line_1'] ?? null,
            $address['locality'] ?? null,
            $address['administrative_district_level_1'] ?? null,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }
}
