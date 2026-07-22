<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Tests;

use AlbertoArena\Truss\TrussServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            TrussServiceProvider::class,
        ];
    }
}
