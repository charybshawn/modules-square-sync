<?php

/*
 * This module's tests. They run inside the host app (it provides the User
 * model, the Event log, the admin layout and the database), which loads this
 * file from vendor/cultpantry/square-sync/tests -- see the host's tests/Pest.php
 * and phpunit.xml. Run them from the host: `php artisan test --testsuite=Modules`.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(__DIR__);

/**
 * Http::fake() entries for the two calls Square Sync's health check makes
 * before it looks at any links: whose token this is, and the sync
 * location. Merge into a test's own fakes:
 *   Http::fake([...squareHealthFakes(), 'connect.../v2/catalog/...' => ...])
 */
if (! function_exists('squareHealthFakes')) {
    function squareHealthFakes(string $merchantId = 'MERCHANT_A', string $locationStatus = 'ACTIVE', ?array $scopes = null): array
    {
        return [
            'connect.squareupsandbox.com/oauth2/token/status' => Illuminate\Support\Facades\Http::response([
                'merchant_id' => $merchantId,
                'scopes' => $scopes ?? [
                    ...Cultpantry\SquareSync\Actions\CheckSquareHealth::REQUIRED_SCOPES,
                    ...Cultpantry\SquareSync\Actions\CheckSquareHealth::TEST_SALE_SCOPES,
                ],
            ], 200),
            'connect.squareupsandbox.com/v2/locations/*' => Illuminate\Support\Facades\Http::response([
                'location' => ['id' => 'L_ANY', 'name' => 'Market Stall', 'status' => $locationStatus],
            ], 200),
        ];
    }
}
