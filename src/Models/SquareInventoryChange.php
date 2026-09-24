<?php

namespace Cultpantry\SquareSync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One Square inventory change (a sale or a restock) that has been applied
 * to local stock -- see the migration for why only applied changes are
 * kept.
 *
 * @property int $id
 * @property string $square_change_id
 * @property string $square_object_id
 * @property int $local_item_id
 * @property string $kind
 * @property int $quantity
 * @property string|null $from_state
 * @property string|null $to_state
 * @property string|null $transaction_id
 * @property string|null $refund_id
 * @property Carbon|null $occurred_at
 */
class SquareInventoryChange extends Model
{
    public const KINDS = ['sale', 'restock'];

    protected $table = 'square_inventory_changes';

    protected $fillable = [
        'square_change_id',
        'square_object_id',
        'local_item_id',
        'kind',
        'quantity',
        'from_state',
        'to_state',
        'transaction_id',
        'refund_id',
        'occurred_at',
    ];

    protected $casts = [
        'local_item_id' => 'integer',
        'quantity' => 'integer',
        'occurred_at' => 'datetime',
    ];

    /**
     * Inserts the ledger row, returning null if this change id was already
     * claimed. Relies on the unique constraint rather than an exists()
     * check first, so two overlapping runs can't both claim the same
     * change -- same approach, and same unique-violation detection, as
     * SquareWebhookEvent::claim(). Callers claim inside the same
     * transaction as the stock write, so a failed write releases the claim
     * with it; the inner DB::transaction() here is a savepoint, which is
     * what keeps a unique violation from aborting that outer transaction
     * on Postgres.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function claim(array $attributes): ?self
    {
        try {
            return DB::transaction(fn () => static::create($attributes));
        } catch (Throwable $e) {
            if ($e instanceof QueryException && in_array($e->errorInfo[0] ?? null, ['23000', '23505'], true)) {
                return null;
            }

            throw $e;
        }
    }
}
