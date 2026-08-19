<?php

namespace Cultpantry\SquareSync\Actions;

use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Square\SquareClient;

/**
 * Walks the Square catalog and returns every ITEM_VARIATION with no
 * existing SquareObjectMapping -- the candidate list for manual linking in
 * the admin UI (see SquareSyncController::catalogItems()).
 *
 * Requested as types=ITEM rather than ITEM_VARIATION (contrast
 * PullSquareCatalog, which only needs the SKU and so requests
 * ITEM_VARIATION directly): Square nests each item's variations inside
 * item_data.variations, which is the only way to get a variation's product
 * name without a second lookup pass -- an ITEM_VARIATION requested on its
 * own carries no parent name, only item_variation_data.item_id.
 *
 * Unlike FetchSquareLocations, failures are allowed to bubble rather than
 * degrade to an empty list: this runs on-demand from a button click (see
 * SquareSyncController::runArtisanCommand()'s equivalent try/catch at the
 * controller boundary), not on every page load, so there's no silent-page
 * tradeoff to make -- the admin should see that the download failed.
 */
class FetchUnlinkedSquareCatalogItems
{
    public function __construct(private readonly SquareClient $client) {}

    /**
     * @return array<int, array{square_object_id: string, square_parent_object_id: ?string, name: string, sku: ?string}>
     */
    public function handle(): array
    {
        if (blank(config('square-sync.access_token'))) {
            return [];
        }

        $mappedIds = SquareObjectMapping::query()->pluck('square_object_id')->flip()->all();
        $items = [];

        foreach ($this->client->catalog()->listItems(['ITEM']) as $item) {
            $itemName = $item['item_data']['name'] ?? '(unnamed item)';

            foreach ($item['item_data']['variations'] ?? [] as $variationObject) {
                if (isset($mappedIds[$variationObject['id']])) {
                    continue;
                }

                $variation = $variationObject['item_variation_data'] ?? [];
                $variationName = $variation['name'] ?? null;

                // Square gives every single-variation item's one variation
                // the name "Regular" -- surfacing that would just be noise
                // for the common case, so it's only appended when it says
                // something a plain item name doesn't (a real variant name
                // like "Large" or "6-pack").
                $name = (filled($variationName) && strcasecmp($variationName, 'Regular') !== 0)
                    ? "{$itemName} — {$variationName}"
                    : $itemName;

                $items[] = [
                    'square_object_id' => $variationObject['id'],
                    'square_parent_object_id' => $item['id'],
                    'name' => $name,
                    'sku' => $variation['sku'] ?? null,
                ];
            }
        }

        usort($items, fn (array $a, array $b) => $a['name'] <=> $b['name']);

        return $items;
    }
}
