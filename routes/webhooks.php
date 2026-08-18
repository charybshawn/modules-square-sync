<?php

use Cultpantry\SquareSync\Http\Controllers\SquareWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Registered by the module rather than in the app's routes/web.php, so that
 * disabling or removing square-sync can't leave a core route file pointing at
 * a class that no longer exists.
 *
 * Deliberately mirrors the core webhook group's prefix/name/middleware
 * (routes/web.php ~line 282) so this lands as webhooks/square alongside
 * webhooks/stripe and webhooks/mailjet -- the 'web' group matters because the
 * CSRF exemption in bootstrap/app.php is keyed on the 'webhooks/square' URI.
 */
Route::prefix('webhooks')
    ->name('webhooks.')
    ->middleware('web')
    ->group(function () {
        Route::post('square', [SquareWebhookController::class, 'handle'])->name('square');
    });
