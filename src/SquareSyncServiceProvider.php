<?php

namespace Cultpantry\SquareSync;

use App\Support\AdminNav;
use Cultpantry\SquareSync\Console\Commands\ImportSquareSales;
use Cultpantry\SquareSync\Console\Commands\PullSquareCatalog;
use Cultpantry\SquareSync\Console\Commands\PullSquareSalesCommand;
use Cultpantry\SquareSync\Console\Commands\ReconcileSquareInventory;
use Cultpantry\SquareSync\Console\Commands\VerifySquareLinksCommand;
use Cultpantry\SquareSync\Contracts\AuditLog;
use Cultpantry\SquareSync\Contracts\LocalCatalog;
use Cultpantry\SquareSync\Contracts\LocalInventory;
use Cultpantry\SquareSync\Contracts\Null\NullAuditLog;
use Cultpantry\SquareSync\Contracts\Null\NullLocalCatalog;
use Cultpantry\SquareSync\Contracts\Null\NullLocalInventory;
use Cultpantry\SquareSync\Contracts\Null\NullSquareSaleRecorder;
use Cultpantry\SquareSync\Contracts\SquareSaleRecorder;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Policies\SquareObjectMappingPolicy;
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
        $this->app->bindIf(LocalCatalog::class, NullLocalCatalog::class);
        $this->app->bindIf(LocalInventory::class, NullLocalInventory::class);
        $this->app->bindIf(AuditLog::class, NullAuditLog::class);
        $this->app->bindIf(SquareSaleRecorder::class, NullSquareSaleRecorder::class);
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
        // Deliberately NOT wrapped in runningInConsole(), the usual idiom for
        // registering package commands: the admin UI's "Run Sync Check"
        // button reaches square:reconcile through Artisan::call() during an
        // HTTP request, where runningInConsole() is false. Guarding this
        // would leave the command registered everywhere except the one place
        // a user can actually click it.
        $this->commands([
            PullSquareCatalog::class,
            ReconcileSquareInventory::class,
            PullSquareSalesCommand::class,
            ImportSquareSales::class,
            VerifySquareLinksCommand::class,
        ]);

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
