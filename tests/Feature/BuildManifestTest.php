<?php

declare(strict_types=1);
use AlbertoArena\Truss\Tests\Support\FunctionInventory;

/*
 * The PHAR's runtime dependency manifest, guarded.
 *
 * It exists separately from the package's own composer.json because the two
 * want opposite things. The published package keeps a deliberately thin
 * require (illuminate/contracts, illuminate/support, laravel-package-tools) and
 * that thinness is a feature. The binary needs the Illuminate components it
 * boots, plus Symfony Console, and putting those in require-dev would make Box
 * either bundle Pest and Testbench too or miss them entirely.
 *
 * **The cost of a second manifest is that it can drift, and nothing in the
 * package's CI looks at it.** These tests are that look. The inventory one
 * matters most: it derives what the console layer imports from the source
 * rather than from a list somebody wrote once, so the next added import fails
 * here instead of inside a built binary.
 */

/** @return array<string, mixed> */
function buildManifest(): array
{
    $path = dirname(__DIR__, 2).'/build/composer.json';

    expect($path)->toBeFile();

    return (array) json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string, string> */
function buildRequires(): array
{
    return (array) (buildManifest()['require'] ?? []);
}

/**
 * Which Illuminate component publishes a given sub-namespace.
 *
 * @return array<string, string>
 */
function illuminateComponents(): array
{
    return [
        'Cache' => 'illuminate/cache',
        'Config' => 'illuminate/config',
        'Container' => 'illuminate/container',
        'Contracts' => 'illuminate/contracts',
        'Database' => 'illuminate/database',
        'Events' => 'illuminate/events',
        'Filesystem' => 'illuminate/filesystem',
        'Log' => 'illuminate/log',
        'Support' => 'illuminate/support',
        'View' => 'illuminate/view',
    ];
}

it('declares every Illuminate component the console layer imports', function (): void {
    // Derived from the source, not from a list: add an import to src/Cli and
    // this fails until the manifest catches up, which is the only way a second
    // manifest stays true.
    $imported = [];

    foreach (glob(dirname(__DIR__, 2).'/src/Cli/**/*.php') + glob(dirname(__DIR__, 2).'/src/Cli/*.php') as $file) {
        preg_match_all('/^use Illuminate\\\\(\w+)\\\\/m', (string) file_get_contents($file), $matches);
        $imported = [...$imported, ...$matches[1]];
    }

    $components = illuminateComponents();
    $required = buildRequires();

    expect(array_unique($imported))->not->toBeEmpty();

    foreach (array_unique($imported) as $namespace) {
        // array_key_exists plus toBeTrue rather than toHaveKey: the second
        // argument to toHaveKey is an expected value, not a failure message,
        // so the tidier spelling silently asserted the wrong thing.
        expect(array_key_exists($namespace, $components))
            ->toBeTrue("Illuminate\\{$namespace} has no known component mapping.");

        expect(array_key_exists($components[$namespace], $required))
            ->toBeTrue("build/composer.json must require {$components[$namespace]}.");
    }
});

it('pins Laravel 12, which is what keeps the PHAR floor at the package floor', function (): void {
    // Q6, settled 05/10/2026: Laravel 13's components require PHP 8.3, so
    // pinning 12 keeps one floor number across the package, the binary, the
    // formula and the guard's message. When ^12.0 leaves the package's own
    // require, this moves to 13 and the floor moves to 8.3 in a release that
    // says so.
    $illuminate = array_filter(buildRequires(), fn (string $k): bool => str_starts_with($k, 'illuminate/'), ARRAY_FILTER_USE_KEY);

    expect($illuminate)->not->toBeEmpty();

    foreach ($illuminate as $package => $constraint) {
        expect($constraint)->toBe('^12.0', "{$package} must be pinned to Laravel 12.");
    }
});

it('declares the same PHP floor as the package', function (): void {
    $package = (array) json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    expect(buildRequires()['php'] ?? null)->toBe($package['require']['php']);
});

it('requires Symfony Console, which the console layer is built on', function (): void {
    // ^7.0 and not ^8.0: Laravel 12 pins Console 7, and the package's own test
    // matrix covers both because the Laravel 13 lanes resolve 8.
    expect(buildRequires()['symfony/console'] ?? null)->toBe('^7.0');
});

it('requires neither the framework nor anything from the test harness', function (string $package): void {
    // The whole reason this file exists. laravel/framework would make the
    // "minimal container" claim false, and a dev dependency in here is how Box
    // ends up shipping Pest inside the binary.
    expect(buildRequires())->not->toHaveKey($package);
})->with(['laravel/framework', 'orchestra/testbench', 'pestphp/pest', 'laravel/pint']);

it('autoloads the Foundation helper shim, without which nothing in src/ runs', function (): void {
    // config() and app() come from Illuminate's Foundation helpers, and
    // Foundation has no composer.json in the framework tree, so it cannot be
    // required. The shim supplies them and has to be loaded before any Truss
    // class: through files autoload, that is guaranteed.
    $files = (array) (buildManifest()['autoload']['files'] ?? []);

    expect($files)->toContain('../src/Cli/Support/functions.php');
});

it('autoloads the package source from the checkout it is built beside', function (): void {
    $psr4 = (array) (buildManifest()['autoload']['psr-4'] ?? []);

    expect($psr4)->toHaveKey('AlbertoArena\\Truss\\')
        ->and($psr4['AlbertoArena\\Truss\\'])->toBe('../src/');
});

it('asks for no dev dependencies at all', function (): void {
    // There is nothing to test from inside the manifest: the suite runs
    // against the package's own vendor directory, and the PHAR ships runtime
    // code only.
    expect(buildManifest())->not->toHaveKey('require-dev');
});

it('declares what env() needs, which illuminate/support does not bring on its own', function (): void {
    // Found 09/10/2026 by installing this manifest and running the binary
    // against it: config/truss.php calls env() about twenty times, and
    // Illuminate\Support\Env reaches for PhpOption\Option and Dotenv's
    // repository builder. The monolithic framework requires phpdotenv, the
    // split support package does not, so the function exists and fatals.
    //
    // The plan's claim that config/truss.php "loads standalone exactly as it
    // is" was therefore half right: the helper is in illuminate/support, its
    // implementation is not. One line here, and the only way to find it was to
    // run the binary on the set this file declares.
    expect(buildRequires())->toHaveKey('vlucas/phpdotenv');
});

/**
 * Paths the binary never loads, with the reason each is unreachable.
 *
 * They may call functions the PHAR's runtime lacks, because nothing in the
 * binary can reach them. Shipping `src/` whole and leaving these dormant is
 * cheaper than carving the tree up in box.json, where the carve would have to
 * be re-derived every time a class moved, and where it would break the HTML
 * export: AssetInliner reads its inventory from AssetController, so src/Http
 * cannot simply be excluded.
 *
 * @return array<string, string>
 */
function dormantPaths(): array
{
    return [
        'Http/' => 'no routes, no requests: the binary serves nothing',
        'Commands/' => 'artisan commands, which the binary does not ship',
        'Mcp/' => 'needs laravel/mcp, which the PHAR does not bundle',
        'Listeners/' => 'migration events, which never happen here',
        'TrussServiceProvider.php' => 'needs spatie/laravel-package-tools',
    ];
}

it('calls no function the PHAR runtime lacks, on any path the binary can reach', function (): void {
    $autoload = dirname(__DIR__, 2).'/build/vendor/autoload.php';

    if (! is_file($autoload)) {
        // Skipped out loud rather than passing: without the runtime installed
        // this assertion cannot fail, and a guard that cannot fail is worse
        // than none. CI installs it as part of building the PHAR.
        $this->markTestSkipped('Run: composer install --working-dir=build --no-dev');
    }

    $calls = FunctionInventory::in(dirname(__DIR__, 2).'/src');

    // Asked of the PHAR's runtime in a subprocess, because this suite runs
    // with laravel/framework loaded, where every Foundation helper exists and
    // the question answers itself the wrong way.
    $probe = 'require $argv[1]; foreach (json_decode($argv[2]) as $f) { if (! function_exists($f)) { echo $f, "\n"; } }';

    exec(sprintf(
        '%s -r %s %s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($probe),
        escapeshellarg($autoload),
        escapeshellarg((string) json_encode(array_keys($calls), JSON_THROW_ON_ERROR)),
    ), $missing);

    $unreachable = array_keys(dormantPaths());
    $live = [];

    foreach (array_filter(array_map('trim', $missing)) as $name) {
        $files = array_filter(
            $calls[$name] ?? [],
            static fn (string $file): bool => ! in_array(true, array_map(
                static fn (string $prefix): bool => str_starts_with($file, $prefix),
                $unreachable,
            ), true),
        );

        // database_path() is the deliberate exception. It is reached only from
        // SnapshotBuilder's migration-replay fallback, which the CLI must never
        // enter: there is no application to replay from, so a snapshot built
        // that way would describe nothing. Leaving it undefined means entering
        // that path fails loudly instead of quietly producing an empty schema.
        if ($name === 'database_path') {
            continue;
        }

        if ($files !== []) {
            $live[$name] = array_values($files);
        }
    }

    expect($live)->toBe([], 'These functions do not exist in the PHAR runtime: '.json_encode($live));
});

it('names a real path and a reason for everything it treats as unreachable', function (): void {
    // So the allow-list cannot grow by one line in a hurry, and so a path that
    // gets renamed or deleted stops silently excusing nothing.
    foreach (dormantPaths() as $path => $reason) {
        expect(file_exists(dirname(__DIR__, 2).'/src/'.rtrim($path, '/')))
            ->toBeTrue("src/{$path} is on the unreachable list but does not exist.")
            ->and(trim($reason))->not->toBeEmpty("src/{$path} needs a reason, not just an entry.");
    }
});
