<?php

use Cultpantry\SquareSync\Http\Controllers\Admin\SquareSyncController;
use Illuminate\Support\Facades\Route;

/*
 * Additive merge into the existing admin route group -- prefix, name, and
 * middleware are identical to the core admin routes, matching the convention
 * established by the costing module. 'web' is required here for the same
 * reason documented on the costing module's routes/admin.php: routes loaded
 * via loadRoutesFrom() from a provider don't get it automatically the way
 * core routes/web.php does, and without it 'auth' silently treats every
 * request as a guest.
 */
Route::prefix('admin')
    ->name('admin.')
    ->middleware(['web', 'auth', 'admin'])
    ->group(function () {
        Route::prefix('square')->name('square.')->group(function () {
            Route::get('/', [SquareSyncController::class, 'index'])->name('index');
            Route::post('unlink/{mapping}', [SquareSyncController::class, 'unlink'])->name('unlink');
            Route::post('health', [SquareSyncController::class, 'health'])->name('health');
            Route::post('sync', [SquareSyncController::class, 'sync'])->name('sync');
            Route::post('resolve-drift', [SquareSyncController::class, 'resolveDrift'])->name('resolve-drift');
            Route::post('diagnostics', [SquareSyncController::class, 'diagnostics'])->name('diagnostics');
            Route::post('test-sale', [SquareSyncController::class, 'startTestSale'])->name('test-sale.start');
            Route::post('test-sale/{run}/check', [SquareSyncController::class, 'checkTestSale'])->whereUuid('run')->name('test-sale.check');
            Route::post('test-sale/{run}/pull', [SquareSyncController::class, 'pullTestSale'])->whereUuid('run')->name('test-sale.pull');
            Route::get('catalog-items', [SquareSyncController::class, 'catalogItems'])->name('catalog-items');
            Route::get('link-preview', [SquareSyncController::class, 'linkPreview'])->name('link-preview');
            Route::post('link', [SquareSyncController::class, 'link'])->name('link');
            Route::post('location', [SquareSyncController::class, 'updateLocation'])->name('location');
        });
    });
