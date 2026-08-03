<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\SchemaExporter;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

function threeTables(): array
{
    return SchemaBuilder::make()
        ->table('zebra', fn ($t) => $t->id())
        ->table('apple', fn ($t) => $t->id())
        ->table('mango', fn ($t) => $t->id())
        ->build()['tables'];
}

it('lists the supported formats and reports support', function () {
    expect(SchemaExporter::formats())->toBe(['dbml', 'json', 'csv', 'markdown', 'mermaid'])
        ->and(SchemaExporter::supports('dbml'))->toBeTrue()
        ->and(SchemaExporter::supports('yaml'))->toBeFalse();
});

it('orders tables alphabetically regardless of input order', function () {
    $names = array_column((new SchemaExporter)->tablesFor(threeTables()), 'name');

    expect($names)->toBe(['apple', 'mango', 'zebra']);
});

it('keeps only the --tables allowlist', function () {
    $names = array_column(
        (new SchemaExporter)->tablesFor(threeTables(), only: ['mango', 'apple']),
        'name',
    );

    expect($names)->toBe(['apple', 'mango']);
});

it('removes the --exclude blocklist after the allowlist', function () {
    $names = array_column(
        (new SchemaExporter)->tablesFor(threeTables(), only: ['mango', 'apple'], exclude: ['apple']),
        'name',
    );

    expect($names)->toBe(['mango']);
});

it('lets config excluded_tables win even over an explicit --tables entry', function () {
    // You cannot filter your way to a config-excluded table.
    $names = array_column(
        (new SchemaExporter)->tablesFor(threeTables(), only: ['mango', 'apple'], configExcluded: ['apple']),
        'name',
    );

    expect($names)->toBe(['mango']);
});

it('sorts each table\'s foreign keys by source column and indexes by name', function () {
    $tables = SchemaBuilder::make()->table('t', function ($t) {
        $t->id();
        $t->foreignId('b_id')->on('other');
        $t->foreignId('a_id')->on('other');
        $t->index('b_id', 'zeta_idx');
        $t->index('a_id', 'alpha_idx');
    })->table('other', fn ($t) => $t->id())->build()['tables'];

    $ordered = (new SchemaExporter)->tablesFor($tables);
    $t = collect($ordered)->firstWhere('name', 't');

    expect(array_column($t['foreign_keys'], 'columns'))->toBe([['a_id'], ['b_id']])
        ->and(array_column($t['indexes'], 'name'))->toBe(['alpha_idx', 'zeta_idx']);
});

it('dispatches to the generator for the requested format', function () {
    $tables = (new SchemaExporter)->tablesFor(threeTables());

    expect((new SchemaExporter)->generate('dbml', $tables))->toContain('Table apple {')
        ->and((new SchemaExporter)->generate('json', $tables))->toStartWith('[');
});

it('throws on an unsupported format', function () {
    (new SchemaExporter)->generate('yaml', []);
})->throws(InvalidArgumentException::class);
