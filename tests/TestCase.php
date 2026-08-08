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
        // The `testing` connection is a real, disposable database so introspection
        // tests run against actual schema behaviour. It defaults to in-memory
        // SQLite (FK enforcement on), which is what `composer test` uses locally.
        // On the CI MySQL and Postgres lanes, DB_CONNECTION (+ the DB_* vars) point
        // it at the service container instead, so the same suite runs against real
        // MySQL/Postgres, which the workstream A `database` annotation path needs.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->resolveTestingConnection());

        // In-memory cache so snapshot caching tests need no cache table.
        $app['config']->set('cache.default', 'array');

        // The HTTP routes run through the `web` middleware group (session +
        // encrypted cookies), so the app needs an encryption key and a
        // driver-less session store under test.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('session.driver', 'array');
    }

    /**
     * The `testing` connection config, selected by DB_CONNECTION. Unset (local
     * default) is in-memory SQLite; 'mysql' and 'pgsql' read the DB_* vars the CI
     * service lanes provide. Every branch is a disposable test database.
     *
     * @return array<string, mixed>
     */
    protected function resolveTestingConnection(): array
    {
        return match (env('DB_CONNECTION', 'sqlite')) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => (string) env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'truss_test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => (string) env('DB_PASSWORD', ''),
                'prefix' => '',
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => (string) env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'truss_test'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => (string) env('DB_PASSWORD', ''),
                'prefix' => '',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        };
    }
}
