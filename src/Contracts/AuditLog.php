<?php

namespace Cultpantry\SquareSync\Contracts;

/**
 * Writes to, and reads back from, the host app's audit trail. Every
 * square.* event this package records goes through here, so the package
 * never references the host's event model or recording action.
 *
 * Entries form trees (a webhook and everything it caused, an API request
 * and its response) via an opaque parent reference, and runs via a
 * correlation id -- the ref returned by record() is only ever handed back
 * to this same implementation as $parentRef, never inspected.
 */
interface AuditLog
{
    /**
     * @param  int|null  $itemId  The LocalCatalog item this entry is about, if any.
     * @param  mixed  $actor  Opaque -- the authenticated user, passed through untouched.
     * @param  array<string, mixed>  $metadata
     * @return int|null a reference for use as a later entry's $parentRef
     */
    public function record(
        string $type,
        string $description,
        ?int $itemId = null,
        mixed $actor = null,
        array $metadata = [],
        string $severity = 'info',
        string $direction = 'internal',
        ?string $correlationId = null,
        ?int $parentRef = null,
    ): ?int;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function webhookReceived(string $eventType, array $metadata, string $correlationId): ?int;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function webhookProcessed(string $eventType, array $metadata, string $correlationId, ?int $parentRef): ?int;

    public function webhookFailed(string $eventType, string $error, string $correlationId, ?int $parentRef): ?int;

    /**
     * Most recent entries of one type, newest first.
     *
     * @return array<int, array{id: int|string, type: string, type_label: string, description: string, severity: string, direction: string|null, created_at: string|null}>
     */
    public function recentOfType(string $type, int $limit): array;

    /**
     * Recent root entries whose type starts with "{$category}.", each with
     * its descendants and correlated entries flattened beneath it -- one
     * group per sync run.
     *
     * @return array<int, array{correlation_id: string|null, started_at: string|null, events: array<int, array>}>
     */
    public function recentActivity(string $category, int $limit): array;
}
