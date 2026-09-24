<?php

namespace Cultpantry\SquareSync\Actions;

use Cultpantry\SquareSync\Jobs\ArchiveSquareCatalogObjectJob;
use Cultpantry\SquareSync\Jobs\PushCatalogObjectJob;
use InvalidArgumentException;

/**
 * Outbound catalog half of the sync: the host calls this when a local
 * item's catalog fields (title, description, price) change, or when the
 * item is archived or restored. Stock is deliberately not a catalog change
 * -- it goes through QueueStockPush only, so one edit never sends Square
 * two writes.
 *
 * Every dispatch is chained ->afterCommit(). Item writes commonly happen
 * inside a transaction, and pushing mid-transaction risks sending Square a
 * change that then rolls back and never actually happened locally.
 */
class QueueCatalogPush
{
    public const UPDATED = 'updated';

    public const ARCHIVED = 'archived';

    public const RESTORED = 'restored';

    public function __construct(private readonly ShouldSyncToSquare $shouldSync) {}

    public function handle(int $itemId, string $change): void
    {
        if (! $this->shouldSync->handle()) {
            return;
        }

        match ($change) {
            self::UPDATED => PushCatalogObjectJob::dispatch($itemId)->afterCommit(),
            self::ARCHIVED => ArchiveSquareCatalogObjectJob::dispatch($itemId, archived: true)->afterCommit(),
            self::RESTORED => ArchiveSquareCatalogObjectJob::dispatch($itemId, archived: false)->afterCommit(),
            default => throw new InvalidArgumentException("Unknown catalog change '{$change}'."),
        };
    }
}
