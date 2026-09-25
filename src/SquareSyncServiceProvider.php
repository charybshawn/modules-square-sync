<?php

namespace Cultpantry\SquareSync;

use App\Support\AdminNav;
use Cultpantry\SquareSync\Console\Commands\CheckSquareHealthCommand;
use Cultpantry\SquareSync\Console\Commands\ImportSquareSales;
use Cultpantry\SquareSync\Console\Commands\PullSquareSalesCommand;
use Cultpantry\SquareSync\Console\Commands\ReconcileSquareInventory;
use Cultpantry\SquareSync\Contracts\AdminAlerts;
use Cultpantry\SquareSync\Contracts\AuditLog;
use Cultpantry\SquareSync\Contracts\LocalCatalog;
use Cultpantry\SquareSync\Contracts\LocalInventory;
use Cultpantry\SquareSync\Contracts\Null\NullAdminAlerts;
use Cultpantry\SquareSync\Contracts\Null\NullAuditLog;
use Cultpantry\SquareSync\Contracts\Null\NullLocalCatalog;
use Cultpantry\SquareSync\Contracts\Null\NullLocalInventory;
use Cultpantry\SquareSync\Contracts\Null\NullSquareSaleRecorder;
use Cultpantry\SquareSync\Contracts\SquareSaleRecorder;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Policies\SquareObjectMappingPolicy;
use Cultpantry\SquareSync\Square\SquareCallRecorder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class SquareSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merged rather than published-only so the module works immediately
        // after composer require, with .env supplying the credentials.
        $this->mergeConfigFrom(__DIR__.'/../config/square-sync.php', 'square-sync');

        // The host app's side of the integration -- its items, its stock
        // funnel, its audit trail -- is only ever reached through these
        // contracts. bindIf() so the host's own integration provider wins
        // regardless of provider order; the null defaults just mean an
        // unintegrated host gets a module that boots and syncs nothing.
        $this->app->scoped(SquareCallRecorder::class);

        $this->app->bindIf(LocalCatalog::class, NullLocalCatalog::class);
        $this->app->bindIf(LocalInventory::class, NullLocalInventory::class);
        $this->app->bindIf(AuditLog::class, NullAuditLog::class);
        $this->app->bindIf(SquareSaleRecorder::class, NullSquareSaleRecorder::class);
        $this->app->bindIf(AdminAlerts::class, NullAdminAlerts::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/admin.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Explicit -- Laravel's naming-convention policy discovery only scans
        // App\Models -> App\Policies, never module namespaces.
        Gate::policy(SquareObjectMapping::class, SquareObjectMappingPolicy::class);

        // No outbound event wiring here: the host tells the module about
        // stock and catalog changes by calling QueueStockPush /
        // QueueCatalogPush from its own listeners, since only the host
        // knows what its events and models are called.

        // Without this the commands exist as classes but Artisan has no idea
        // they're there -- package commands get no auto-discovery, so
        // `php artisan square:reconcile` would fail with "command not found"
        // in a real app even though the class is loadable.
        //
        $this->commands([
            CheckSquareHealthCommand::class,
            ReconcileSquareInventory::class,
            PullSquareSalesCommand::class,
            ImportSquareSales::class,
        ]);

        // The module owns its schedule, so a host only needs the usual
        // `schedule:run` cron -- and uninstalling the module can't leave the
        // host scheduling commands that no longer exist.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (! config('square-sync.schedule')) {
                return;
            }

            // Connection + every link, alerting admins when either breaks.
            $schedule->command('square:check')->everyFifteenMinutes()->withoutOverlapping();

            // Catch-up for the webhooks: applies any Square sale a dropped
            // inventory webhook missed, and records new sales and refunds.
            // Idempotent, so overlapping the webhooks is safe.
            $schedule->command('square:pull-sales')->everyFifteenMinutes()->withoutOverlapping();

            // Report-only drift check. Fixing (--fix) is deliberately not
            // scheduled: a human reads square.drift_detected first.
            $schedule->command('square:reconcile')->hourly()->withoutOverlapping();
        });

        AdminNav::register([
            'name' => 'Square Sync',
            'href' => '/admin/square',
            'icon' => 'square-sync',
            'match' => '/admin/square',
            'module' => 'cultpantry/square-sync',
        ]);

        $this->publishes([
            __DIR__.'/../config/square-sync.php' => config_path('square-sync.php'),
        ], 'square-sync-config');

        // Published into the same root the core Inertia glob (resources/js/app.js)
        // already scans, so no extra Vite or test config is needed.
        $this->publishes([
            __DIR__.'/../resources/js/Pages' => resource_path('js/Pages/Vendor/square-sync'),
        ], 'square-sync-pages');
    }
}
