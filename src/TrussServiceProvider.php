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
         * This package's configuration, routes, views, and commands are wired
         * up in later phases. For now the skeleton just names the package so it
         * boots cleanly and is discoverable.
         */
        $package->name('laravel-truss');
    }
}
