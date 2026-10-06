<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cli\Bootstrapper;
use AlbertoArena\Truss\Cli\Dsn;
use Illuminate\Container\Container;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

/*
 * There is deliberately no Testbench here, and no application. That is the
 * whole point of the exercise: this is the container the framework-free binary
 * boots, and every assertion below is something `src/` needs from it. A test
 * that reached for the package TestCase would prove nothing about the CLI.
 */

/** @var array<string, mixed> */
const CONNECTION = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];

afterEach(function (): void {
    // The bootstrapper owns two global statics, so a test that leaves them set
    // would leak into the next one and into the rest of the suite.
    Container::setInstance(null);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
});

it('resolves every service the package reaches for', function (string $binding): void {
    $container = Bootstrapper::boot(CONNECTION);

    expect($container->bound($binding))->toBeTrue("Expected [{$binding}] to be bound.");
})->with(['config', 'cache', 'log', 'db', 'db.schema', 'events', 'view', 'files']);

it('loads the package config, so config() answers with the packaged defaults', function (): void {
    Bootstrapper::boot(CONNECTION);

    // 3600 is the default in config/truss.php. The point is not the number: it
    // is that the CLI reads the package's own config file rather than a copy.
    expect(config('truss.cache.ttl'))->toBe(3600)
        ->and(config('database.default'))->toBe('truss');
});

it('supports the write form of config, which the snapshot fallback uses', function (): void {
    // SnapshotBuilder sets database.default to swap in its SQLite fallback and
    // then puts the original back, so a read-only config() breaks a real run.
    Bootstrapper::boot(CONNECTION);

    config(['database.default' => 'somewhere-else']);

    expect(config('database.default'))->toBe('somewhere-else');
});

it('registers itself as the container instance, because the helpers resolve through that static', function (): void {
    $container = Bootstrapper::boot(CONNECTION);

    // app() and config(), whether Foundation's or the PHAR's shim, both go
    // through Container::getInstance(). Without this the helpers resolve
    // against nothing and every config() call in src/ fails.
    expect(Container::getInstance())->toBe($container)
        ->and(app('config'))->toBe($container->make('config'));
});

it('wires the facades, because src/ reads the schema through them', function (): void {
    $container = Bootstrapper::boot(CONNECTION);

    $container->make('db')->connection('truss')->statement('create table widgets (id integer primary key)');

    expect(Schema::connection('truss')->hasTable('widgets'))->toBeTrue()
        ->and(Schema::hasTable('widgets'))->toBeTrue();
});

it('binds files for Blade but never a filesystems disk', function (): void {
    // Q7 measured that no CLI path reaches Storage, and that still holds, so a
    // disk manager appearing here means somebody put a Storage call on a CLI
    // path. `files` is the opposite case: Blade compiles the HTML export's
    // view, so it is required rather than forbidden.
    $container = Bootstrapper::boot(CONNECTION);

    expect($container->bound('filesystems'))->toBeFalse()
        ->and($container->bound(FilesystemManager::class))->toBeFalse()
        ->and($container->make('files'))->toBeInstanceOf(Filesystem::class);
});

it('resolves the package view namespace, so the HTML export can render', function (): void {
    $container = Bootstrapper::boot(CONNECTION);

    // truss-package, not truss: the private namespace that cannot be overridden
    // by a published copy of the view. HtmlGenerator renders exactly this.
    expect($container->make(ViewFactory::class)->exists('truss-package::index'))->toBeTrue();
});

it('compiles Blade to a writable path outside the package', function (): void {
    $container = Bootstrapper::boot(CONNECTION);

    $compiled = $container->make('config')->get('view.compiled');

    // Inside a PHAR the package tree is read-only, so a compiled path under it
    // fails on the first render rather than at boot.
    expect(is_dir($compiled))->toBeTrue()
        ->and(is_writable($compiled))->toBeTrue()
        ->and(str_starts_with($compiled, dirname(__DIR__, 2)))->toBeFalse();
});

it('accepts what the DSN parser produces, which is the seam between the two', function (): void {
    // Both sides are tested on their own; this pins that the keys the parser
    // emits are the keys the Capsule wants. It is the join that would break
    // silently, because a connection array with a wrong key does not fail
    // until something queries it.
    $container = Bootstrapper::boot(Dsn::parse('sqlite::memory:'));

    $container->make('db')->connection(Bootstrapper::CONNECTION)->statement('create table gadgets (id integer primary key)');

    expect(Schema::connection(Bootstrapper::CONNECTION)->hasTable('gadgets'))->toBeTrue();
});
