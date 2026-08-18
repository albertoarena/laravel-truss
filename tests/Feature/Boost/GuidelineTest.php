<?php

declare(strict_types=1);

use AlbertoArena\Truss\Commands\ExportCommand;

/**
 * The Boost guideline is a shipped artefact with invariants worth guarding.
 *
 * Laravel Boost discovers third-party content by scanning each installed
 * package for `resources/boost/guidelines/` and `resources/boost/skills/`, so
 * the path is a contract: moving the file silently ends the integration, with
 * no error anywhere. The content is agent-facing instruction, so the wording
 * carries the structure-only boundary and has to keep carrying it.
 *
 * These tests need no Boost installed. The one test that asserts Boost itself
 * discovers us lives separately, because it needs the package present.
 */
$guidelinePath = __DIR__.'/../../../resources/boost/guidelines/truss.md';

$guideline = fn (): string => (string) file_get_contents(
    __DIR__.'/../../../resources/boost/guidelines/truss.md'
);

it('ships the guideline at the path Boost scans', function () use ($guidelinePath) {
    expect($guidelinePath)->toBeReadableFile();
});

it('derives the Boost guideline key from what is actually in the directory', function () {
    // Boost keys a third-party guideline as `<vendor>/<package>/<relative path
    // without extension>`, so this file is `albertoarena/laravel-truss/truss`.
    // That key is what a user puts in `boost.guidelines.exclude`, so a rename
    // would orphan every existing exclusion, and a second file added here would
    // appear in everyone's standing context unannounced. Read the directory
    // rather than a path constant, so both cases fail this test.
    $files = glob(__DIR__.'/../../../resources/boost/guidelines/*') ?: [];

    $keys = array_map(
        fn (string $file): string => 'albertoarena/laravel-truss/'.pathinfo($file, PATHINFO_FILENAME),
        $files
    );

    expect($keys)->toBe(['albertoarena/laravel-truss/truss']);
});

it('states the structure-only boundary', function () use ($guideline) {
    // The agent acts on this text, so the boundary is not decoration.
    expect($guideline())
        ->toContain('Structure only')
        ->toContain('never row data')
        ->toContain('Truss never returns row data');
});

it('teaches the commands an agent can actually run', function () use ($guideline) {
    expect($guideline())
        ->toContain('truss:export')
        ->toContain('truss:doctor')
        ->toContain('truss:diff');
});

it('mentions the MCP server only as optional', function () use ($guideline) {
    // Boost cannot register a third-party MCP server, so presenting ours as a
    // prerequisite would be false. It is one path among three.
    expect($guideline())
        ->toContain('optionally available')
        ->toContain('It is not required');
});

it('names only flags that exist on the export command', function () use ($guideline) {
    // Guards duplication drift: the guideline hardcodes flags that live in
    // ExportCommand, so a rename there would silently make this file lie.
    $signature = (new ReflectionClass(ExportCommand::class))
        ->getDefaultProperties()['signature'];

    preg_match_all('/--([a-z][a-z-]*)/', $guideline(), $matches);

    expect(array_unique($matches[1]))->not->toBeEmpty();

    foreach (array_unique($matches[1]) as $flag) {
        expect($signature)->toContain('--'.$flag);
    }
});

it('stays inside the standing-context token budget', function () use ($guidelinePath) {
    // Boost injects guidelines into the agent's context for every request, so
    // size is a feature. Bytes are a deterministic, dependency-free proxy for
    // the 500-token hard cap at roughly 3.6 bytes per token.
    expect(filesize($guidelinePath))->toBeLessThan(1800);
});

it('ships as plain Markdown, not Blade', function () use ($guideline) {
    // Boost renders `.blade.php` guidelines through its own context, where a
    // failure surfaces inside someone else's boost:install with our package
    // named and no first-party fallback. A static file cannot break.
    expect(__DIR__.'/../../../resources/boost/guidelines/truss.blade.php')
        ->not->toBeFile();

    expect($guideline())
        ->not->toContain('@if')
        ->not->toContain('@foreach')
        ->not->toContain('{{');
});

it('uses no em or en dashes', function () use ($guideline) {
    expect($guideline())
        ->not->toContain('—')
        ->not->toContain('–');
});

it('is not excluded from the dist archive', function () {
    // resources/ ships while docs/ and tests/ do not. A future .gitattributes
    // edit that caught resources/boost would break the integration in the
    // published package while every test here still passed.
    $gitattributes = (string) file_get_contents(__DIR__.'/../../../.gitattributes');

    foreach (explode("\n", $gitattributes) as $line) {
        if (! str_contains($line, 'export-ignore')) {
            continue;
        }

        $pattern = trim(explode('export-ignore', $line)[0]);

        expect(ltrim($pattern, '/'))->not->toStartWith('resources');
    }
});
