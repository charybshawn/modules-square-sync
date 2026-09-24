<?php

namespace Cultpantry\SquareSync\Contracts\Sales;

/**
 * The customer attached to a Square sale -- from Square's customer
 * directory when the order has a customer_id, otherwise from a
 * fulfillment's recipient (then $squareCustomerId is null). Any field can
 * be missing -- a walk-up POS sale usually has no customer at all
 * (SquareSale::$customer is then null), and one entered at the counter may
 * have only a name.
 */
final class SquareCustomer
{
    public function __construct(
        public readonly ?string $squareCustomerId,
        public readonly ?string $name,
        public readonly ?string $email,
        public readonly ?string $phone,
    ) {}
}
