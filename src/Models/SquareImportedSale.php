<?php

namespace Cultpantry\SquareSync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One Square sale or refund already handed to the host's
 * SquareSaleRecorder.
 *
 * @property int $id
 * @property string $kind
 * @property string $square_id
 * @property string $square_order_id
 * @property string|null $local_reference
 * @property Carbon|null $occurred_at
 */
class SquareImportedSale extends Model
{
    public const KINDS = ['sale', 'refund'];

    protected $table = 'square_imported_sales';

    protected $fillable = [
        'kind',
        'square_id',
        'square_order_id',
        'local_reference',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    /**
     * Same claim-by-unique-constraint approach as
     * SquareInventoryChange::claim() -- see there for why the insert is
     * wrapped in its own savepoint.
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
