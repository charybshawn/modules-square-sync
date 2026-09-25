<?php

namespace Cultpantry\SquareSync\Contracts\Sales;

use Carbon\CarbonImmutable;

/**
 * A refund of (part of) an earlier Square sale, reported once. $amount is a
 * positive decimal string. $lines is empty for an amount-only refund.
 *
 * The sale it refunds may not have been recorded (e.g. it predates the
 * backfill window) -- the host decides what to do with an orphan.
 */
final class SquareRefund
{
    public const ENV_SANDBOX = SquareSale::ENV_SANDBOX;

    public const ENV_PRODUCTION = SquareSale::ENV_PRODUCTION;

    /**
     * @param  array<int, SquareRefundLine>  $lines
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $squareRefundId,
        public readonly string $squareOrderId,
        public readonly CarbonImmutable $refundedAt,
        public readonly string $currency,
        public readonly string $amount,
        public readonly ?string $reason,
        public readonly array $lines,
        public readonly array $raw,
        // 'sandbox' or 'production' -- sandbox sales are test data, which
        // a host should keep apart from real sales.
        public readonly string $environment = self::ENV_PRODUCTION,
    ) {}
}
