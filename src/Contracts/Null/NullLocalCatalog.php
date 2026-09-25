<?php

namespace Cultpantry\SquareSync\Contracts\Null;

use Cultpantry\SquareSync\Contracts\LocalCatalog;
use Cultpantry\SquareSync\Contracts\LocalItem;

/**
 * Default binding for a host that hasn't implemented LocalCatalog: an
 * empty catalog. Nothing can be linked, so nothing is pushed or pulled --
 * the module boots and its admin page renders, but syncs nothing.
 */
class NullLocalCatalog implements LocalCatalog
{
    public function morphType(): string
    {
        return 'unbound';
    }

    public function find(int $id, bool $withTrashed = false): ?LocalItem
    {
        return null;
    }

    public function findMany(array $ids, bool $withTrashed = false): array
    {
        return [];
    }

    public function listExcluding(array $excludeIds, int $limit): array
    {
        return ['items' => [], 'total' => 0];
    }

    public function archive(int $id): void {}
}
