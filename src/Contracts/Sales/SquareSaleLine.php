<?php

namespace Cultpantry\SquareSync\Contracts\Sales;

/**
 * One line item of a Square sale. Money is a decimal string in the sale's
 * currency ("12.50"), converted from Square's integer minor units.
 *
 * $localItemId is the LocalCatalog item the Square variation is linked
 * to, or null for an unlinked variation or a custom-amount line -- the
 * line is still reported, since revenue is revenue whether or not the item
 * is linked.
 */
final class SquareSaleLine
{
    public function __construct(
        public readonly string $squareLineUid,
        public readonly ?string $squareVariationId,
        public readonly ?int $localItemId,
        public readonly string $name,
        public readonly ?string $variationName,
        public readonly int $quantity,
        public readonly string $unitPrice,
        public readonly string $discount,
        public readonly string $tax,
        public readonly string $total,
    ) {}
}
