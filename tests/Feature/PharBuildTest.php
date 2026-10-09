<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cli\Version;

/*
 * The PHAR's build configuration.
 *
 * Compiling is not something this suite can do: Box is not a dependency of the
 * package, and writing a PHAR needs phar.readonly off. So these assertions are
 * about the configuration rather than the artifact, and the release workflow's
 * smoke lane is what proves a built binary runs. What is asserted here is the
 * handful of settings that are load-bearing and easy to change without
 * noticing, each with the reason it matters.
 */

/** @return array<string, mixed> */
function boxConfig(): array
{
    $path = dirname(__DIR__, 2).'/box.json';

    expect($path)->toBeFile();

    return (array) json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

/** @return list<string> */
function boxIncludes(): array
{
    $in = [];

    foreach ((array) (boxConfig()['finder'] ?? []) as $finder) {
        $in = [...$in, ...(array) ($finder['in'] ?? [])];
    }

    return $in;
}

it('builds from the entry point the binary actually is', function (): void {
    expect(boxConfig()['main'] ?? null)->toBe('bin/truss')
        ->and(boxConfig()['output'] ?? null)->toBe('truss.phar');
});

it('stamps the version placeholder the binary reads', function (): void {
    // The one setting that ties two files together. Box replaces @name@
    // wherever it appears, and Version reads a constant holding exactly that
    // token, so renaming either without the other produces a binary whose
    // --version silently reports "dev" forever. Derived from the constant
    // rather than written out twice.
    $placeholder = '@'.(string) (boxConfig()['git-version'] ?? '').'@';

    expect($placeholder)->toBe(Version::PLACEHOLDER);
});

it('does not dump an autoloader, because the one it would dump is the wrong one', function (): void {
    // Box derives an autoloader from the composer.json in its base path, which
    // here is the package's own: a thin require with none of the components
    // the binary boots. The archive carries build/vendor's generated
    // autoloader instead, which is why the finder includes that directory.
    expect(boxConfig()['dump-autoload'] ?? null)->toBeFalse();
});

it('bundles the runtime, the source, the config and the frontend assets', function (string $path): void {
    // resources/ is the one people forget, and it fails at runtime rather than
    // at build time: AssetInliner reads the stylesheet, the fonts, the module
    // graph and the vendored Mermaid from there, so without it the HTML export
    // throws on its first inline while every other command passes.
    expect(boxIncludes())->toContain($path);
})->with(['src', 'config', 'resources', 'build/vendor']);

it('bundles the floor guard, which runs before the autoloader', function (): void {
    $names = [];

    foreach ((array) (boxConfig()['finder'] ?? []) as $finder) {
        if (in_array('bin', (array) ($finder['in'] ?? []), true)) {
            $names = [...$names, ...(array) ($finder['name'] ?? [])];
        }
    }

    expect($names)->toContain('php-floor.php');
});

it('bundles neither the test suite nor the development vendor directory', function (string $path): void {
    // The package's own vendor holds Testbench and Pest, and the whole reason
    // build/composer.json exists is to keep them out of the binary.
    expect(boxIncludes())->not->toContain($path);
})->with(['tests', 'vendor', '.']);

it('leaves the requirement check to the binary itself', function (): void {
    // Box can prepend its own requirement checker. The entry point already
    // checks the PHP floor, in PHP 7 syntax, before the autoloader runs, and
    // prints a message naming both versions. Two checkers would print two
    // messages, and the one the Homebrew formula depends on is ours, because
    // it is the one the smoke lane asserts.
    expect(boxConfig()['check-requirements'] ?? null)->toBeFalse();
});

it('compresses the archive, because a quarter of it is a vendored diagram library', function (): void {
    // Measured 09/10/2026: 14.45 MB uncompressed, 3.70 MB with GZ, almost all
    // of the difference being minified JavaScript and the Illuminate
    // components. GZ needs zlib at runtime, which every PHP build has.
    expect(boxConfig()['compression'] ?? null)->toBe('GZ');
});
