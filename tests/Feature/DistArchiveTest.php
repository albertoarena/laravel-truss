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

/**
 * Everything `git archive HEAD` would ship, as a list of paths.
 *
 * HEAD rather than the working tree on purpose, and it is not a detail: git
 * reads `export-ignore` from the tree being archived, so a `.gitattributes`
 * edited but not committed changes nothing here. That is also what makes this
 * the real check. The attribute assertions above describe intent; this one
 * describes what a release would actually contain.
 *
 * @return list<string>
 */
function distArchiveEntries(): array
{
    $root = dirname(__DIR__, 2);
    $output = [];
    exec('git -C '.escapeshellarg($root).' archive HEAD | tar -t 2>/dev/null', $output);

    return array_values(array_filter(array_map('trim', $output), fn (string $line): bool => $line !== ''));
}

function distArchiveTracks(string $path): int
{
    $root = dirname(__DIR__, 2);
    $output = [];
    exec('git -C '.escapeshellarg($root).' ls-files -- '.escapeshellarg($path), $output);

    return count(array_filter($output, fn (string $line): bool => trim($line) !== ''));
}

it('builds an archive at all, so the assertions below are not vacuous', function () {
    // If the archive came back empty, every exclusion assertion in this file
    // would pass and prove nothing. This is the control.
    $entries = distArchiveEntries();

    expect($entries)->not->toBeEmpty()
        ->and(array_filter($entries, fn (string $e): bool => str_starts_with($e, 'src/')))->not->toBeEmpty()
        ->and(array_filter($entries, fn (string $e): bool => str_starts_with($e, 'resources/')))->not->toBeEmpty();
});

it('ships no console layer in the archive a release is built from', function (string $path) {
    if (distArchiveTracks($path) === 0) {
        // bin/ and build/ do not exist yet, so an assertion about them would
        // pass whatever .gitattributes said. Skipped out loud rather than
        // left silently green, because a guard that cannot fail is the exact
        // failure this file exists to prevent. Remove the skip by adding the
        // directory, not by deleting this branch.
        $this->markTestSkipped("[{$path}] is not tracked yet, so asserting its absence proves nothing.");
    }

    $shipped = array_values(array_filter(
        distArchiveEntries(),
        fn (string $entry): bool => str_starts_with($entry, $path.'/'),
    ));

    expect($shipped)->toBe([], "Expected no {$path} entries in the dist archive.");
})->with(['src/Cli', 'bin', 'build']);
