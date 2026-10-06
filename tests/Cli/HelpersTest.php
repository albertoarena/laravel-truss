<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cli\Support\Helpers;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;

/*
 * `config()` and `app()` are defined in Illuminate's Foundation helpers, and
 * Foundation is the one Illuminate component that is not published on its own,
 * so a PHAR built from the components has neither function while `src/` calls
 * `config()` 53 times. The shim supplies them.
 *
 * What this file can and cannot cover, stated so a green run is not read as
 * more than it is. A development clone has laravel/framework installed through
 * Testbench, so the global `config()` here is always Foundation's. These tests
 * therefore cover the logic, which lives in a class for exactly that reason,
 * plus the guard that keeps the shim from colliding with Foundation. **That the
 * functions exist inside the built PHAR is a smoke-lane assertion**, not
 * something this suite can see.
 */

final class Widget
{
    public function __construct(public readonly string $label = 'default') {}
}

beforeEach(function (): void {
    $container = new Container;
    $container->instance('config', new ConfigRepository(['truss' => ['cache' => ['ttl' => 3600]]]));
    Container::setInstance($container);
});

afterEach(function (): void {
    Container::setInstance(null);
});

it('reads a config value by dotted key', function (): void {
    expect(Helpers::config('truss.cache.ttl'))->toBe(3600);
});

it('returns the default for a key that is not set', function (): void {
    expect(Helpers::config('truss.nothing.here', 'fallback'))->toBe('fallback');
});

it('returns the repository itself when given no key', function (): void {
    expect(Helpers::config())->toBeInstanceOf(ConfigRepository::class);
});

it('writes when given an array, which the snapshot fallback depends on', function (): void {
    // SnapshotBuilder swaps database.default to its SQLite fallback and then
    // restores it, both through the array form. A read-only shim would pass
    // every test above and break on the first unreachable connection.
    Helpers::config(['database.default' => 'fallback']);

    expect(Helpers::config('database.default'))->toBe('fallback');
});

it('resolves the container itself when app() is given nothing', function (): void {
    expect(Helpers::app())->toBe(Container::getInstance());
});

it('resolves a class, with constructor parameters, as SchemaExporter needs', function (): void {
    // SchemaExporter::generate() calls app()->make($generator, $context) to
    // hand the HTML generator its view factory and asset inliner.
    expect(Helpers::app(Widget::class))->toBeInstanceOf(Widget::class)
        ->and(Helpers::app(Widget::class, ['label' => 'given'])->label)->toBe('given');
});

it('says what is wrong when nothing has been bootstrapped', function (): void {
    // Without this the failure is "Target class [config] does not exist",
    // which sends the reader looking for a class rather than for the boot call.
    Container::setInstance(null);

    expect(fn (): mixed => Helpers::config('truss.cache.ttl'))
        ->toThrow(RuntimeException::class, 'Truss is not bootstrapped');
});

it('guards its definitions, so loading it beside Foundation cannot redeclare', function (): void {
    // Foundation's helpers are loaded in this suite, so requiring the shim
    // here is the real test of the guard: without it, PHP fatals on
    // "Cannot redeclare config()" and takes the whole run with it.
    // functions.php rather than helpers.php: the class beside it is
    // Helpers.php, and on a case-insensitive macOS volume those two names are
    // one file. Illuminate uses functions.php for the same purpose.
    require dirname(__DIR__, 2).'/src/Cli/Support/functions.php';

    expect(function_exists('config'))->toBeTrue()
        ->and(function_exists('app'))->toBeTrue()
        ->and(config('truss.cache.ttl'))->toBe(3600);
});
