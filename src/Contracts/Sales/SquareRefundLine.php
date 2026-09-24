<?php

namespace Cultpantry\SquareSync\Contracts\Sales;

/**
 * One returned line of a Square refund. $squareLineUid points back at the
 * original sale's SquareSaleLine::$squareLineUid when Square links it.
 */
final class SquareRefundLine
{
    public function __construct(
        public readonly ?string $squareLineUid,
        public readonly ?string $squareVariationId,
        public readonly ?int $localItemId,
        public readonly string $name,
        public readonly int $quantity,
        public readonly string $amount,
    ) {}
}
