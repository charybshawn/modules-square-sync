<?php

namespace Cultpantry\SquareSync\Contracts;

/**
 * Looks up (and, for Square-side deletions, archives) local items on the
 * host app's behalf -- bound in the host's own service provider to whatever
 * model actually represents a sellable item there (e.g. App\Models\Product).
 * This package never queries that table directly.
 */
interface LocalCatalog
{
    /**
     * The value stored in square_object_mappings.mappable_type for items
     * from this catalog -- kept in the mapping table (rather than a
     * package-owned constant) so rows written before this contract existed,
     * which already hold the host model's morph class, keep resolving.
     */
    public function morphType(): string;

    public function find(int $id, bool $withTrashed = false): ?LocalItem;

    /**
     * @param  array<int, int>  $ids
     * @return array<int, LocalItem> keyed by item id; ids that don't
     *                               resolve are simply absent
     */
    public function findMany(array $ids, bool $withTrashed = false): array;

    /**
     * Live (non-trashed) items whose id isn't in $excludeIds, ordered by
     * title -- the admin page's "not yet linked" panel.
     *
     * @param  array<int, int>  $excludeIds
     * @return array{items: array<int, LocalItem>, total: int}
     */
    public function listExcluding(array $excludeIds, int $limit): array;

    /**
     * Soft-deletes (archives) the item because its Square catalog object
     * was deleted. Must be a no-op for an item that's already archived.
     */
    public function archive(int $id): void;
}
