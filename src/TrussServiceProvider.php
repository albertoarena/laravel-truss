<?php

declare(strict_types=1);

namespace AlbertoArena\Truss;

use AlbertoArena\Truss\Commands\OpenCommand;
use AlbertoArena\Truss\Commands\RebuildCommand;
use AlbertoArena\Truss\Commands\ShowCommand;
use AlbertoArena\Truss\Http\Controllers\AssetController;
use AlbertoArena\Truss\Http\Controllers\IndexController;
use AlbertoArena\Truss\Http\Controllers\SchemaApiController;
use AlbertoArena\Truss\Http\Middleware\Authorize;
use AlbertoArena\Truss\Listeners\RebuildOnMigrationsEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class TrussServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * The package short name is "truss" (spatie strips the "laravel-"
         * prefix), so hasConfigFile() merges config/truss.php under the "truss"
         * config key and registers it for publishing (tag: truss-config).
         *
         * Views are registered under the "truss::" namespace; routes and the
         * authorization gate are wired up in packageBooted().
         */
        $package
            ->name('laravel-truss')
            ->hasConfigFile()
            ->hasViews()
            ->hasCommands([RebuildCommand::class, ShowCommand::class, OpenCommand::class]);
    }

    public function packageBooted(): void
    {
        // Rebuild the cached snapshot after migrations run. The listener itself
        // respects truss.enabled, so it is always registered and decides at
        // runtime whether to act.
        Event::listen(MigrationsEnded::class, RebuildOnMigrationsEnded::class);

        $this->registerGate();
        $this->registerRoutes();
    }

    /**
     * The fixed `viewTruss` gate. The shipped default admits the emails listed
     * in truss.authorization.allowed_emails — the zero-code path for gating a
     * production install to a set of admins. It is only ever consulted in
     * non-local environments (the Authorize middleware skips it in local), and a
     * host app fully replaces it by defining its own `viewTruss` gate (e.g. a
     * role check), which then takes precedence. The ability name is fixed — only
     * the callback varies, and that lives in the app.
     */
    private function registerGate(): void
    {
        if (! Gate::has('viewTruss')) {
            Gate::define('viewTruss', fn ($user = null): bool => $user !== null
                && in_array($user->email ?? null, (array) config('truss.authorization.allowed_emails', []), true));
        }
    }

    /**
     * The two routes (index page + JSON schema endpoint) under the configured
     * prefix. The configured auth-context middleware runs first so the gate can
     * see the authenticated user; the `viewTruss` authorization guard is always
     * appended and cannot be configured away.
     */
    private function registerRoutes(): void
    {
        Route::group([
            'prefix' => config('truss.route_prefix'),
            'middleware' => [...(array) config('truss.middleware', ['web']), Authorize::class],
        ], function (): void {
            Route::get('/', IndexController::class)->name('truss.index');
            Route::get('/api/schema', SchemaApiController::class)->name('truss.api.schema');
            // Static assets served from the package (no publish step, no CDN).
            // Gated with everything else so they never confirm Truss exists.
            Route::get('/assets/{file}', AssetController::class)->name('truss.asset');
        });
    }
}
