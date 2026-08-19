<?php

namespace Cultpantry\SquareSync\Actions;

use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Square\SquareClient;

/**
 * Walks the Square catalog and returns every ITEM_VARIATION with no
 * existing SquareObjectMapping -- the candidate list for manual linking in
 * the admin UI (see SquareSyncController::catalogItems()).
 *
 * Requests both ITEM and ITEM_VARIATION in one paginated call and matches
 * this codebase's one proven pattern for reading them (PullSquareCatalog's
 * own listItems(['ITEM_VARIATION']) call): ListCatalog's `types` filter
 * returns flat top-level objects of each requested type, not variations
 * nested inside their parent item -- an earlier version of this class
 * assumed item_data.variations would be populated by requesting ITEM alone
 * and silently found nothing on every item, which read in the UI as "every
 * catalog item is already linked" even when nothing was linked at all.
 * ITEM objects are collected into an id->name lookup in the same pass, used
 * once the whole stream has been drained so it doesn't matter which order
 * Square returns an item relative to its own variations.
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

        $itemNames = [];
        $variations = [];

        foreach ($this->client->catalog()->listItems(['ITEM', 'ITEM_VARIATION']) as $object) {
            if (($object['type'] ?? null) === 'ITEM') {
                $itemNames[$object['id']] = $object['item_data']['name'] ?? null;

                continue;
            }

            if (($object['type'] ?? null) === 'ITEM_VARIATION' && ! isset($mappedIds[$object['id']])) {
                $variations[] = $object;
            }
        }

        $items = array_map(function (array $object) use ($itemNames) {
            $variation = $object['item_variation_data'] ?? [];
            $itemId = $variation['item_id'] ?? null;
            $itemName = ($itemId !== null ? $itemNames[$itemId] ?? null : null) ?? '(unnamed item)';
            $variationName = $variation['name'] ?? null;

            // Square gives every single-variation item's one variation the
            // name "Regular" -- surfacing that would just be noise for the
            // common case, so it's only appended when it says something a
            // plain item name doesn't (a real variant name like "Large" or
            // "6-pack").
            $name = (filled($variationName) && strcasecmp($variationName, 'Regular') !== 0)
                ? "{$itemName} — {$variationName}"
                : $itemName;

            return [
                'square_object_id' => $object['id'],
                'square_parent_object_id' => $itemId,
                'name' => $name,
                'sku' => $variation['sku'] ?? null,
            ];
        }, $variations);

        usort($items, fn (array $a, array $b) => $a['name'] <=> $b['name']);

        return $items;
    }
}
