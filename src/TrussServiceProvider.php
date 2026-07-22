<?php

declare(strict_types=1);

namespace AlbertoArena\Truss;

use AlbertoArena\Truss\Commands\RebuildCommand;
use AlbertoArena\Truss\Listeners\RebuildOnMigrationsEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
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
         * Routes and views are wired up in later phases.
         */
        $package
            ->name('laravel-truss')
            ->hasConfigFile()
            ->hasCommand(RebuildCommand::class);
    }

    public function packageBooted(): void
    {
        // Rebuild the cached snapshot after migrations run. The listener itself
        // respects truss.enabled, so it is always registered and decides at
        // runtime whether to act.
        Event::listen(MigrationsEnded::class, RebuildOnMigrationsEnded::class);
    }
}
