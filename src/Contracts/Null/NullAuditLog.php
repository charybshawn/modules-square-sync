<?php

namespace Cultpantry\SquareSync\Contracts\Null;

use Cultpantry\SquareSync\Contracts\AuditLog;

/**
 * Default binding for a host without an audit trail: records nothing,
 * reads back nothing.
 */
class NullAuditLog implements AuditLog
{
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
    ): ?int {
        return null;
    }

    public function webhookReceived(string $eventType, array $metadata, string $correlationId): ?int
    {
        return null;
    }

    public function webhookProcessed(string $eventType, array $metadata, string $correlationId, ?int $parentRef): ?int
    {
        return null;
    }

    public function webhookFailed(string $eventType, string $error, string $correlationId, ?int $parentRef): ?int
    {
        return null;
    }

    public function recentOfType(string $type, int $limit): array
    {
        return [];
    }

    public function recentActivity(string $category, int $limit): array
    {
        return [];
    }
}
