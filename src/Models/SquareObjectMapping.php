<?php

namespace Cultpantry\SquareSync\Models;

use Cultpantry\SquareSync\Database\Factories\SquareObjectMappingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * One row per Square catalog object (a CatalogItem or an ItemVariation)
 * linked to a local record. mappable_* is polymorphic on purpose -- the
 * only mappable type today is App\Models\Product, but Square tracks
 * inventory at the ItemVariation level under a CatalogItem parent, and this
 * app has neither an external-id column on products nor a product-variants
 * table yet. Keeping the link polymorphic means real variants can be mapped
 * later without touching this table's schema.
 *
 * square_parent_object_id is set only when square_object_type is
 * ITEM_VARIATION, and points at the parent CatalogItem's id.
 *
 * last_pushed_hash exists to break the catalog echo loop: Square's
 * catalog.version.updated webhook fires on our own writes too, so the pull
 * handler compares an inbound payload's hash against last_pushed_hash to
 * tell "Square changed this independently" from "we just pushed this
 * ourselves" -- see markPushed().
 *
 * Soft-deletes rather than hard-deletes on unlink(), so a mapping's sync
 * history survives being unlinked.
 *
 * @property int $id
 * @property string $mappable_type
 * @property int $mappable_id
 * @property string $square_object_id
 * @property string|null $square_parent_object_id
 * @property string $square_object_type
 * @property int|null $square_version
 * @property Carbon|null $last_pushed_at
 * @property Carbon|null $last_pulled_at
 * @property string|null $last_pushed_hash
 * @property string $sync_status
 */
class SquareObjectMapping extends Model
{
    use HasFactory, SoftDeletes;

    public const SYNC_STATUSES = ['linked', 'pending', 'conflict', 'orphaned'];

    protected $table = 'square_object_mappings';

    protected $fillable = [
        'mappable_type',
        'mappable_id',
        'square_object_id',
        'square_parent_object_id',
        'square_object_type',
        'square_version',
        'last_pushed_at',
        'last_pulled_at',
        'last_pushed_hash',
        'sync_status',
    ];

    protected $casts = [
        'square_version' => 'integer',
        'last_pushed_at' => 'datetime',
        'last_pulled_at' => 'datetime',
    ];

    /**
     * This package's composer.json only autoloads Cultpantry\SquareSync\ ->
     * src/, so database/factories/ (required by the WP2 spec) sits outside
     * any PSR-4 root Composer knows about and the usual convention-based
     * factory resolution (Database\Factories\{Model}Factory) can't find it
     * either. require_once-ing the file directly sidesteps both: PHP
     * doesn't need autoloading once a class has been required, only the
     * *first* reference to it does. Registering an actual autoload rule for
     * database/factories/ belongs in composer.json, which is out of scope
     * here -- see the WP2 report for the exact change needed.
     */
    /**
     * Explicit, because Factory's name-guessing only rewrites the App\Models
     * prefix into Database\Factories -- it has no rule that would reach a
     * factory living under a package namespace.
     */
    protected static function newFactory(): Factory
    {
        return SquareObjectMappingFactory::new();
    }

    public function mappable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeLinked(Builder $query): Builder
    {
        return $query->where('sync_status', 'linked');
    }

    public function scopeConflicted(Builder $query): Builder
    {
        return $query->where('sync_status', 'conflict');
    }

    public function scopeOrphaned(Builder $query): Builder
    {
        return $query->where('sync_status', 'orphaned');
    }

    public function scopeForSquareId(Builder $query, string $id): Builder
    {
        return $query->where('square_object_id', $id);
    }

    /**
     * Records a successful push to Square. $version, when Square returned
     * one in the response, replaces our stored optimistic-concurrency
     * token so the next push is made against the version Square now has.
     */
    public function markPushed(string $hash, ?int $version = null): void
    {
        $this->forceFill([
            'last_pushed_at' => now(),
            'last_pushed_hash' => $hash,
            'square_version' => $version ?? $this->square_version,
            'sync_status' => 'linked',
        ])->save();
    }

    /**
     * Records a successful pull from Square.
     */
    public function markPulled(?int $version = null): void
    {
        $this->forceFill([
            'last_pulled_at' => now(),
            'square_version' => $version ?? $this->square_version,
            'sync_status' => 'linked',
        ])->save();
    }

    /**
     * There's no conflict_reason column -- this table's job is current
     * state (are we linked, pending, conflicted?), not an audit trail of
     * every reason a conflict was raised, so $reason is logged rather than
     * persisted. Add a column here if that history needs to become
     * queryable later.
     */
    public function markConflict(string $reason): void
    {
        Log::warning('Square object mapping conflict', [
            'square_object_mapping_id' => $this->id,
            'square_object_id' => $this->square_object_id,
            'mappable_type' => $this->mappable_type,
            'mappable_id' => $this->mappable_id,
            'reason' => $reason,
        ]);

        $this->forceFill(['sync_status' => 'conflict'])->save();
    }

    /**
     * Idempotent link/relink. withTrashed() + restore(), rather than a
     * plain updateOrCreate(), because unlink() soft-deletes: square_
     * object_id stays unique across trashed rows too, so re-linking the
     * same Square object after an unlink must revive the old row instead
     * of colliding with it on a fresh insert.
     */
    public static function linkTo(Model $model, string $squareObjectId, string $type, ?string $parentId = null): self
    {
        $mapping = static::withTrashed()->firstOrNew(['square_object_id' => $squareObjectId]);

        $mapping->fill([
            'mappable_type' => $model->getMorphClass(),
            'mappable_id' => $model->getKey(),
            'square_parent_object_id' => $parentId,
            'square_object_type' => $type,
            'sync_status' => 'linked',
        ]);

        if ($mapping->trashed()) {
            $mapping->restore();
        }

        $mapping->save();

        return $mapping;
    }

    /**
     * Soft delete, so this mapping's sync history (last_pushed_at,
     * last_pushed_hash, etc.) survives the unlink instead of being erased.
     */
    public function unlink(): void
    {
        $this->delete();
    }
}
