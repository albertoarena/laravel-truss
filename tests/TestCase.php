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

    protected function defineEnvironment($app): void
    {
        // A real, disposable SQLite connection with FK enforcement on, so
        // introspection tests run against actual schema behaviour.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        // In-memory cache so snapshot caching tests need no cache table.
        $app['config']->set('cache.default', 'array');

        // The HTTP routes run through the `web` middleware group (session +
        // encrypted cookies), so the app needs an encryption key and a
        // driver-less session store under test.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('session.driver', 'array');
    }
}
