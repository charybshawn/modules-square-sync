<?php

namespace Cultpantry\SquareSync\Contracts\Null;

use Cultpantry\SquareSync\Contracts\Sales\SquareRefund;
use Cultpantry\SquareSync\Contracts\Sales\SquareSale;
use Cultpantry\SquareSync\Contracts\SquareSaleRecorder;

/**
 * Default binding for a host that doesn't keep Square sales records: stock
 * still syncs, sales just aren't recorded anywhere.
 */
class NullSquareSaleRecorder implements SquareSaleRecorder
{
    public function recordSale(SquareSale $sale): ?string
    {
        return null;
    }

    public function recordRefund(SquareRefund $refund): ?string
    {
        return null;
    }
}
