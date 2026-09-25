<?php

namespace Cultpantry\SquareSync\Actions;

use Cultpantry\SquareSync\Contracts\AuditLog;
use Cultpantry\SquareSync\Contracts\LocalCatalog;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Square\SquareClient;

/**
 * Checks every product link still points at something real on the Square
 * account this module is connected to -- links go stale when an item is
 * deleted or replaced on Square, archived, or taken off the sync location,
 * none of which a link notices on its own.
 *
 * One batch-retrieve covers every linked variation (and, via related
 * objects, its parent item). Each link is classified and stamped:
 *
 *  - ok:              exists, active, and sold at the sync location
 *  - missing:         not on this account (deleted, or from another
 *                     environment/account) -- the link is marked orphaned,
 *                     so pushes and Square sales skip it
 *  - archived:        its item is archived on Square, so it can't be sold
 *  - not_at_location: not available at the sync location, so stock pushed
 *                     there has nothing to land on
 *
 * Nothing is unlinked automatically: the admin decides (Unlink / Relink on
 * the Square Sync page). A missing link whose item reappears goes back to
 * linked on the next check.
 */
class VerifySquareLinks
{
    public function __construct(
        private readonly SquareClient $client,
        private readonly LocalCatalog $catalog,
        private readonly GetSquareLocationId $getLocationId,
        private readonly AuditLog $auditLog,
    ) {}

    /**
     * @return array{
     *     checked: int,
     *     ok: int,
     *     missing: int,
     *     archived: int,
     *     not_at_location: int,
     *     issues: array<int, array{mapping_id: int, product_id: int, product_title: string|null, square_object_id: string, status: string}>,
     * }
     */
    public function handle(?string $correlationId = null): array
    {
        // Orphaned links are included so they can recover.
        $mappings = SquareObjectMapping::forLocalCatalog()->get();

        $tally = ['checked' => $mappings->count(), 'ok' => 0, 'missing' => 0, 'archived' => 0, 'not_at_location' => 0, 'issues' => []];

        if ($mappings->isEmpty()) {
            return $tally;
        }

        $found = $this->client->catalog()->batchRetrieve($mappings->pluck('square_object_id')->all());
        $locationId = $this->getLocationId->handle();
        $items = $this->catalog->findMany($mappings->pluck('mappable_id')->all(), withTrashed: true);
        $verifiedAt = now();

        foreach ($mappings as $mapping) {
            $status = $this->classify($found['objects'][$mapping->square_object_id] ?? null, $found['related'], $locationId);

            $tally[$status]++;

            $mapping->forceFill([
                'verification_status' => $status,
                'verified_at' => $verifiedAt,
                // Only a missing object stops syncing; a link that comes
                // back is live again.
                'sync_status' => match (true) {
                    $status === 'missing' => 'orphaned',
                    $mapping->sync_status === 'orphaned' => 'linked',
                    default => $mapping->sync_status,
                },
            ])->save();

            if ($status !== 'ok') {
                $tally['issues'][] = [
                    'mapping_id' => $mapping->id,
                    'product_id' => $mapping->mappable_id,
                    'product_title' => $items[$mapping->mappable_id]->title ?? null,
                    'square_object_id' => $mapping->square_object_id,
                    'status' => $status,
                ];
            }
        }

        $problems = $tally['missing'] + $tally['archived'] + $tally['not_at_location'];

        $this->auditLog->record(
            type: 'square.links_verified',
            description: $problems === 0
                ? "Square links verified -- all {$tally['checked']} OK"
                : "Square links verified -- {$problems} of {$tally['checked']} need attention ({$tally['missing']} missing, {$tally['archived']} archived, {$tally['not_at_location']} not at the sync location)",
            metadata: collect($tally)->except('issues')->all(),
            severity: $problems === 0 ? 'info' : 'warning',
            direction: 'inbound',
            correlationId: $correlationId,
        );

        return $tally;
    }

    /**
     * @param  array<string, array>  $related
     * @return 'ok'|'missing'|'archived'|'not_at_location'
     */
    private function classify(?array $object, array $related, ?string $locationId): string
    {
        if ($object === null || ($object['is_deleted'] ?? false) === true) {
            return 'missing';
        }

        // Links are to a variation (square:pull-catalog, manual linking) or,
        // for items this app created on Square, to the item itself.
        $item = ($object['type'] ?? null) === 'ITEM_VARIATION'
            ? ($related[$object['item_variation_data']['item_id'] ?? ''] ?? null)
            : $object;

        if ($item !== null && ($item['is_deleted'] ?? false) === true) {
            return 'missing';
        }

        if (($item['item_data']['is_archived'] ?? false) === true) {
            return 'archived';
        }

        if ($locationId !== null && (! $this->presentAt($object, $locationId) || ($item !== null && ! $this->presentAt($item, $locationId)))) {
            return 'not_at_location';
        }

        return 'ok';
    }

    /**
     * Square's per-object location rule: present everywhere (minus any
     * absent_at_location_ids) or only at present_at_location_ids.
     */
    private function presentAt(array $object, string $locationId): bool
    {
        if ($object['present_at_all_locations'] ?? true) {
            return ! in_array($locationId, $object['absent_at_location_ids'] ?? [], true);
        }

        return in_array($locationId, $object['present_at_location_ids'] ?? [], true);
    }
}
