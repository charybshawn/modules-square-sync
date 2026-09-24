<?php

namespace Cultpantry\SquareSync\Contracts\Null;

use Cultpantry\SquareSync\Contracts\LocalInventory;

/**
 * Default binding paired with NullLocalCatalog -- with no items there is no
 * stock to move.
 */
class NullLocalInventory implements LocalInventory
{
    public function applySquareSale(int $itemId, int $quantity, array $metadata = []): int
    {
        return 0;
    }

    public function applySquareRestock(int $itemId, int $quantity, array $metadata = []): int
    {
        return 0;
    }

    public function setFromSquareAtLink(int $itemId, int $quantity, mixed $actor = null, array $metadata = []): int
    {
        return 0;
    }
}
