<?php

namespace Cultpantry\SquareSync;

use App\Events\StockUpdated;
use App\Models\Product;
use App\Support\AdminNav;
use Cultpantry\SquareSync\Console\Commands\PullSquareCatalog;
use Cultpantry\SquareSync\Console\Commands\ReconcileSquareInventory;
use Cultpantry\SquareSync\Listeners\PushStockToSquare;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Observers\ProductObserver;
use Cultpantry\SquareSync\Policies\SquareObjectMappingPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class SquareSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merged rather than published-only so the module works immediately
        // after composer require, with .env supplying the credentials.
        $this->mergeConfigFrom(__DIR__.'/../config/square-sync.php', 'square-sync');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/admin.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Explicit -- Laravel's naming-convention policy discovery only scans
        // App\Models -> App\Policies, never module namespaces.
        Gate::policy(SquareObjectMapping::class, SquareObjectMappingPolicy::class);

        // Outbound sync wiring (app -> Square). Explicit for the same
        // reason as the policy above: Laravel's listener auto-discovery
        // only scans App\Listeners, never a package namespace, and there's
        // no observer-discovery convention at all -- both must be
        // registered by hand here rather than relying on attributes.
        Event::listen(StockUpdated::class, PushStockToSquare::class);
        Product::observe(ProductObserver::class);

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
