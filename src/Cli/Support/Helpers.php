<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Cli\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * The two Illuminate helper functions the PHAR has to supply itself.
 *
 * `config()` and `app()` live in `Illuminate/Foundation/helpers.php`, and
 * Foundation is the one Illuminate component with no `composer.json` of its
 * own, so it is not published and cannot be required. The framework-free
 * binary therefore has neither function, while `src/` makes 53 `config()`
 * calls and `SchemaExporter::generate()` calls `app()->make()`.
 *
 * The logic lives here rather than in the function bodies for one reason: a
 * development clone has laravel/framework installed, so the global functions
 * are always Foundation's there and the shim's own behaviour would never be
 * exercised by a test. A class is testable in both worlds.
 *
 * The behaviour matches Foundation's deliberately, including the array form of
 * `config()` that writes. Divergence would mean `src/` behaving differently
 * under the binary than under artisan, which is the thing the parity tests
 * exist to prevent.
 */
final class Helpers
{
    /**
     * Read a config value, the whole repository, or write a set of them.
     *
     * @param  array<string, mixed>|string|null  $key
     */
    public static function config(array|string|null $key = null, mixed $default = null): mixed
    {
        $repository = self::repository();

        if ($key === null) {
            return $repository;
        }

        if (is_array($key)) {
            return $repository->set($key);
        }

        return $repository->get($key, $default);
    }

    /**
     * Resolve the container, or something out of it.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function app(?string $abstract = null, array $parameters = []): mixed
    {
        $container = Container::getInstance();

        if ($abstract === null) {
            return $container;
        }

        return $container->make($abstract, $parameters);
    }

    /**
     * Without this the first `config()` call fails with "Target class [config]
     * does not exist", which reads as a missing class rather than as a missing
     * boot, and sends the reader looking in the wrong place.
     */
    private static function repository(): Repository
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            throw new RuntimeException('Truss is not bootstrapped: no configuration is bound. Boot the CLI container before calling into the package.');
        }

        return $container->make('config');
    }
}
