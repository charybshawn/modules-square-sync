<?php

namespace Cultpantry\SquareSync\Contracts;

use Cultpantry\SquareSync\Contracts\Sales\SquareRefund;
use Cultpantry\SquareSync\Contracts\Sales\SquareSale;

/**
 * Receives every Square sale and refund, once each, so the host can keep
 * its own sales records (reporting, customer history) -- bound in the
 * host's integration provider to whatever its orders system is.
 *
 * Record-only. Implementations must NOT change stock: this package moves
 * stock itself, from Square's inventory change log, through
 * LocalInventory. A recorder that also deducted stock would take every
 * sale off twice -- and sales imported by square:import-sales happened
 * before this sync existed and are already reflected in stock.
 *
 * Sales made while connected to Square's sandbox arrive with
 * $environment 'sandbox'. They're test data: keep them apart from real
 * sales, and don't attribute them to real customers.
 *
 * Exactly-once is this package's job (it claims each Square id before
 * calling in, inside the same transaction), but implementations should
 * still key their records on the Square ids so a manual replay is
 * harmless.
 */
interface SquareSaleRecorder
{
    /**
     * @return string|null the host's own reference for the record, for the audit trail
     */
    public function recordSale(SquareSale $sale): ?string;

    /**
     * @return string|null the host's own reference for the record, for the audit trail
     */
    public function recordRefund(SquareRefund $refund): ?string;
}
