<?php

declare(strict_types=1);

namespace AlbertoArena\Truss;

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
         * Routes, views, and commands are wired up in later phases.
         */
        $package
            ->name('laravel-truss')
            ->hasConfigFile();
    }
}
