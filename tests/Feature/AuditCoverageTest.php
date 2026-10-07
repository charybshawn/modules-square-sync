<?php

use Illuminate\Support\Facades\Route;

/*
 * Every action must be auditable. Each route this module registers that
 * changes data records through the Contracts\\AuditLog the host binds to its Event log. A new write route fails here until it does and
 * is added below.
 */
it('audits every route in this module that changes data', function () {
    $audited = [
        'POST admin/square/diagnostics',
        'POST admin/square/health',
        'POST admin/square/link',
        'POST admin/square/location',
        'POST admin/square/resolve-drift',
        'POST admin/square/sync',
        'POST admin/square/test-sale',
        'POST admin/square/test-sale/{run}/check',
        'POST admin/square/test-sale/{run}/pull',
        'POST admin/square/unlink/{mapping}',
        'POST webhooks/square',
    ];

    $writeRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with((string) $r->getActionName(), 'Cultpantry\\SquareSync\\'))
        ->flatMap(fn ($r) => collect($r->methods())
            ->intersect(['POST', 'PUT', 'PATCH', 'DELETE'])
            ->map(fn ($m) => "{$m} {$r->uri()}"))
        ->unique()->sort()->values();

    expect($writeRoutes->diff($audited)->values()->all())->toBe([])
        ->and(collect($audited)->diff($writeRoutes)->values()->all())->toBe([]);
});
