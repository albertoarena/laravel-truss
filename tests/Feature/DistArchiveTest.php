<?php

declare(strict_types=1);

/**
 * What this asserts is a Composer property: an installer receives the package
 * and no console layer. It is NOT a provenance or reproducibility check, and a
 * green run here says nothing about whether a published artifact can rebuild
 * the release binary. See the CLI plan's Q9.
 */
function exportIgnoreIsSetFor(string $path): bool
{
    $output = [];
    exec('git -C '.escapeshellarg(dirname(__DIR__, 2)).' check-attr export-ignore -- '.escapeshellarg($path), $output);

    return str_ends_with(trim(implode("\n", $output)), ': set');
}

it('keeps the console layer out of the dist archive', function (string $path) {
    // The CLI, its entry point and the PHAR build manifest live in this repo
    // but must never reach a vendor/ directory: shipping them would mean the
    // published package carrying Symfony Console and the Illuminate CLI
    // dependencies that build/composer.json pins for the PHAR alone.
    expect(exportIgnoreIsSetFor($path))->toBeTrue("Expected {$path} to be export-ignored.");
})->with(['src/Cli', 'bin', 'build']);

it('still ships the runtime the package needs', function (string $path) {
    // The guard above is one .gitattributes line away from excluding something
    // installers depend on. src/ and resources/ (views, css, js, the vendored
    // Mermaid, fonts) are the package.
    expect(exportIgnoreIsSetFor($path))->toBeFalse("Expected {$path} to ship.");
})->with(['src', 'resources', 'config', 'composer.json', 'LICENSE']);
