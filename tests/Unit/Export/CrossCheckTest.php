<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\DbmlGenerator;
use AlbertoArena\Truss\Export\MarkdownGenerator;
use AlbertoArena\Truss\Export\MermaidGenerator;

/**
 * Half of the PHP<->JS cross-check. The browser still ships its own DBML,
 * Markdown, and Mermaid generators (resources/js), and the download button uses
 * them until the Phase 3 route swap. To guarantee the CLI and the browser
 * produce the same file meanwhile, both implementations are pinned to the same
 * golden fixtures: this test asserts the PHP generators match them, and
 * tests/js/export-cross-check.test.js asserts the JS generators match the very
 * same files. Equal-to-the-same means equal to each other.
 *
 * Only the three whole-schema formats are cross-checked. JSON and CSV have no
 * comparable browser counterpart (the browser exports a single table), so they
 * are covered by their own PHP golden tests instead.
 */
function exportFixtureDir(): string
{
    return dirname(__DIR__, 2).'/Fixtures/export';
}

/**
 * @return list<array<string, mixed>>
 */
function exportFixtureTables(): array
{
    return json_decode((string) file_get_contents(exportFixtureDir().'/schema.json'), true);
}

it('DbmlGenerator matches the shared golden fixture', function () {
    expect((new DbmlGenerator)->generate(exportFixtureTables()))
        ->toBe(file_get_contents(exportFixtureDir().'/schema.dbml'));
});

it('MarkdownGenerator matches the shared golden fixture', function () {
    expect((new MarkdownGenerator)->generate(exportFixtureTables()))
        ->toBe(file_get_contents(exportFixtureDir().'/schema.md'));
});

it('MermaidGenerator matches the shared golden fixture', function () {
    expect((new MermaidGenerator)->generate(exportFixtureTables()))
        ->toBe(file_get_contents(exportFixtureDir().'/schema.mmd'));
});
