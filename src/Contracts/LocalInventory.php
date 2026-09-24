<?php

namespace Cultpantry\SquareSync\Contracts;

/**
 * The only way this package changes local stock. Every method routes
 * through whatever the host's own stock-mutation funnel is -- locking,
 * audit trail, and broadcasting are entirely the host's responsibility.
 *
 * Local stock is the source of truth: Square may only move it for a sale
 * (and the refund that reverses one), never for a recount or manual edit
 * made in Square's dashboard. That rule is enforced by this contract's
 * shape -- there is deliberately no "set local stock to Square's count"
 * method except setFromSquareAtLink(), the one-time admin-confirmed
 * choice made when an item is first linked.
 *
 * The host must NOT treat a change made through here as something to push
 * back to Square as a local edit *except* for sales and restocks: after a
 * sale, pushing the resulting local count is exactly what keeps Square
 * matched to local. setFromSquareAtLink() must never be pushed back (it
 * came from Square in the first place).
 */
interface LocalInventory
{
    /**
     * Decrements stock by $quantity for a Square sale. Clamps at 0 rather
     * than throwing -- the sale has already happened on Square, so refusing
     * it locally would only lose the record of it.
     *
     * @param  array<string, mixed>  $metadata
     * @return int the resulting stock level
     */
    public function applySquareSale(int $itemId, int $quantity, array $metadata = []): int;

    /**
     * Increments stock by $quantity for a refunded Square sale whose items
     * were restocked.
     *
     * @param  array<string, mixed>  $metadata
     * @return int the resulting stock level
     */
    public function applySquareRestock(int $itemId, int $quantity, array $metadata = []): int;

    /**
     * Sets stock to Square's count, once, when an admin links an item and
     * explicitly chooses "Square's count is correct".
     *
     * @param  mixed  $actor  Opaque -- passed straight through to the host's
     *                        own audit trail, never inspected by this package.
     * @param  array<string, mixed>  $metadata
     * @return int the resulting stock level
     */
    public function setFromSquareAtLink(int $itemId, int $quantity, mixed $actor = null, array $metadata = []): int;
}
