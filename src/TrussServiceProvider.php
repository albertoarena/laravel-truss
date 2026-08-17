<?php

declare(strict_types=1);

namespace AlbertoArena\Truss;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Commands\DiffCommand;
use AlbertoArena\Truss\Commands\DoctorCommand;
use AlbertoArena\Truss\Commands\ExportCommand;
use AlbertoArena\Truss\Commands\OpenCommand;
use AlbertoArena\Truss\Commands\RebuildCommand;
use AlbertoArena\Truss\Commands\ShowCommand;
use AlbertoArena\Truss\Export\Contracts\CommentReader;
use AlbertoArena\Truss\Export\DatabaseCommentReader;
use AlbertoArena\Truss\Http\Controllers\AssetController;
use AlbertoArena\Truss\Http\Controllers\ExportController;
use AlbertoArena\Truss\Http\Controllers\IndexController;
use AlbertoArena\Truss\Http\Controllers\SchemaApiController;
use AlbertoArena\Truss\Http\Controllers\ThemeController;
use AlbertoArena\Truss\Http\Middleware\Authorize;
use AlbertoArena\Truss\Listeners\RebuildOnMigrationsEnded;
use AlbertoArena\Truss\Mcp\TrussSchemaServer;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
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
            ->hasCommands([RebuildCommand::class, ShowCommand::class, OpenCommand::class, DiffCommand::class, DoctorCommand::class, ExportCommand::class]);
    }

    public function packageRegistered(): void
    {
        // The DB-comment source behind the export Annotator. Bound to an
        // interface so it can be faked in tests without a real connection.
        $this->app->bind(CommentReader::class, DatabaseCommentReader::class);

        // One repository per request or command, so that whoever reads the
        // snapshot and whoever reports on it (a controller, a command, the export
        // builder behind the facade) share the same lastError(). Scoped rather
        // than a singleton because that error is per-request state.
        $this->app->scoped(SchemaCacheRepository::class);

        // The entry point behind the Truss facade (the fluent export builder).
        $this->app->singleton(TrussManager::class);
    }

    public function packageBooted(): void
    {
        // Rebuild the cached snapshot after migrations run. The listener itself
        // respects truss.enabled, so it is always registered and decides at
        // runtime whether to act.
        Event::listen(MigrationsEnded::class, RebuildOnMigrationsEnded::class);

        $this->registerGate();
        $this->registerRoutes();
        $this->registerMcpServer();
    }

    /**
     * Register the optional read-only, structure-only MCP server when the
     * optional laravel/mcp package is installed and the feature is enabled. A
     * host that does not install laravel/mcp is byte-identical to today: the
     * guard short-circuits on the missing class. The decision lives in
     * shouldRegisterMcpServer() so it stays unit-testable.
     */
    private function registerMcpServer(): void
    {
        if (! self::shouldRegisterMcpServer()) {
            return;
        }

        // Self-register the local (stdio) server. The laravel/mcp Registrar is a
        // singleton bound by its own provider, so a package can register from
        // boot; the host starts it with `php artisan mcp:start truss`.
        Mcp::local('truss', TrussSchemaServer::class);
    }

    /**
     * Whether the MCP server should be registered: the feature is enabled and
     * the optional laravel/mcp package is installed. The class_exists guard is
     * what keeps a host that has not opted in byte-identical to today.
     */
    public static function shouldRegisterMcpServer(): bool
    {
        return (bool) config('truss.mcp.enabled', true)
            && class_exists(Mcp::class);
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
            // Structural export in a chosen format, driving the same pipeline as
            // the command and facade. Gated with everything else; PNG and SVG stay
            // client-side (they are canvas/DOM artifacts with no server form).
            Route::get('/export/{format}', ExportController::class)->name('truss.export');
            // Optional custom-theme stylesheet, generated from truss.theme.
            // Same-origin so a strict CSP keeps style-src 'self'; gated with the
            // rest so it never confirms Truss exists to the unauthorized.
            Route::get('/theme.css', ThemeController::class)->name('truss.theme');
            // Static assets served from the package (no publish step, no CDN).
            // Gated with everything else so they never confirm Truss exists.
            Route::get('/assets/{file}', AssetController::class)->name('truss.asset');
        });
    }
}
