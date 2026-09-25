<?php

namespace Cultpantry\SquareSync\Contracts\Sales;

use Carbon\CarbonImmutable;

/**
 * One completed Square order (POS sale, paid invoice, ...), reported to
 * the host once, for its own sales records. Money is a decimal string in
 * $currency. $total = $subtotal - $discount + $tax + $tip.
 */
final class SquareSale
{
    public const ENV_SANDBOX = 'sandbox';

    public const ENV_PRODUCTION = 'production';

    public const CHANNEL_POS = 'pos';

    public const CHANNEL_INVOICE = 'invoice';

    public const CHANNEL_OTHER = 'other';

    public const PAYMENT_CARD = 'card';

    public const PAYMENT_CASH = 'cash';

    public const PAYMENT_MIXED = 'mixed';

    public const PAYMENT_OTHER = 'other';

    /**
     * @param  array<int, SquareSaleLine>  $lines
     * @param  array<string, mixed>  $raw  Square's order payload, for the host to keep as-is
     */
    public function __construct(
        public readonly string $squareOrderId,
        public readonly string $locationId,
        public readonly string $channel,
        public readonly ?string $sourceName,
        public readonly CarbonImmutable $placedAt,
        public readonly string $currency,
        public readonly string $subtotal,
        public readonly string $discount,
        public readonly string $tax,
        public readonly string $tip,
        public readonly string $total,
        public readonly string $paymentMethod,
        public readonly ?SquareCustomer $customer,
        public readonly array $lines,
        public readonly array $raw,
        // 'sandbox' or 'production' -- sandbox sales are test data, which
        // a host should keep apart from real sales.
        public readonly string $environment = self::ENV_PRODUCTION,
    ) {}
}
