<?php

namespace Cultpantry\SquareSync\Contracts;

/**
 * One local sellable/stockable record (e.g. a storefront product), as seen
 * by this package. A plain snapshot built by the host's LocalCatalog
 * implementation -- the package never knows which model backs it, so it
 * can link, push, and report on an item without referencing a products
 * table, a stock_quantity column, or any host Eloquent model.
 *
 * A snapshot, not a live handle: stock can change the moment after it was
 * taken, so anything that has to act on the *current* quantity (a stock
 * push, a sale being applied) goes back through LocalCatalog/LocalInventory
 * rather than trusting $stockQuantity here.
 */
final class LocalItem
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $sku,
        public readonly ?string $description,
        public readonly string $price,
        public readonly ?string $currency,
        public readonly int $stockQuantity,
        public readonly bool $tracksInventory,
        public readonly bool $trashed = false,
    ) {}
}
