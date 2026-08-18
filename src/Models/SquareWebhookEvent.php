<?php

namespace Cultpantry\SquareSync\Models;

use Cultpantry\SquareSync\Database\Factories\SquareWebhookEventFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Inbound idempotency ledger for Square webhooks. Square retries
 * deliveries aggressively, and the existing Stripe integration in this app
 * has no event-id dedupe at all -- every delivery has to be claim()'d
 * before any processing happens, so a retried delivery is a no-op rather
 * than double-processed inventory or catalog data.
 *
 * error doubles as the free-text explanation for both 'failed' and
 * 'skipped' status -- there's no separate reason column, since both cases
 * boil down to "here's why this event didn't result in processed data".
 *
 * @property int $id
 * @property string $square_event_id
 * @property string $event_type
 * @property array $payload
 * @property string $status
 * @property string|null $error
 * @property Carbon|null $processed_at
 */
class SquareWebhookEvent extends Model
{
    use HasFactory;

    public const STATUSES = ['received', 'processed', 'failed', 'skipped'];

    protected $table = 'square_webhook_events';

    protected $fillable = [
        'square_event_id',
        'event_type',
        'payload',
        'status',
        'error',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    /**
     * See SquareObjectMapping::newFactory() for why this is explicit.
     */
    protected static function newFactory(): Factory
    {
        return SquareWebhookEventFactory::new();
    }

    /**
     * The idempotency gate. Returns the newly-created row on the first
     * call for a given square_event_id, or null if that event id has
     * already been claimed.
     *
     * Guarantee this relies on: the unique constraint on square_event_id
     * at the database level, not an application-level check-then-insert.
     * A "does it exist? then create" approach (firstOrCreate, or exists()
     * followed by create()) is NOT safe under concurrent duplicate
     * deliveries -- both requests can pass the existence check before
     * either one's insert has committed, and both would proceed to
     * process the same event. Instead this attempts the insert directly
     * and lets the database be the single source of truth: when two
     * requests race, exactly one INSERT succeeds and the other fails the
     * unique constraint, which is caught below and treated as "already
     * claimed by someone else".
     */
    public static function claim(string $squareEventId, string $eventType, array $payload): ?self
    {
        try {
            return DB::transaction(function () use ($squareEventId, $eventType, $payload) {
                return static::create([
                    'square_event_id' => $squareEventId,
                    'event_type' => $eventType,
                    'payload' => $payload,
                    'status' => 'received',
                ]);
            });
        } catch (Throwable $e) {
            if (static::isUniqueConstraintViolation($e)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * SQLSTATE 23000 covers MySQL and SQLite (the test suite's driver)
     * integrity-constraint violations; 23505 is Postgres's more specific
     * unique_violation code. Checked via errorInfo rather than message
     * matching so this isn't brittle to driver-specific wording.
     */
    protected static function isUniqueConstraintViolation(Throwable $e): bool
    {
        return $e instanceof QueryException
            && in_array($e->errorInfo[0] ?? null, ['23000', '23505'], true);
    }

    public function markProcessed(): void
    {
        $this->forceFill([
            'status' => 'processed',
            'processed_at' => now(),
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => 'failed',
            'error' => $error,
        ])->save();
    }

    public function markSkipped(string $reason): void
    {
        $this->forceFill([
            'status' => 'skipped',
            'error' => $reason,
        ])->save();
    }
}
