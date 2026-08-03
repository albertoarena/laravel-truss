<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\SchemaExporter;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

/**
 * The export must be byte-identical between runs and independent of the order
 * the introspection layer happened to return tables, foreign keys, and indexes
 * in. This is what lets a CI job commit an export file and fail only on a real
 * schema change, never on incidental reordering.
 */
function richSchema(): array
{
    return SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('roles', fn ($t) => $t->id())
        ->table('posts', function ($t) {
            $t->id();
            $t->foreignId('user_id')->on('users');
            $t->column('status', "enum('draft','published')");
            $t->index('status', 'posts_status_index');
            $t->unique('user_id', 'posts_user_unique');
        })
        ->table('role_user', function ($t) {
            $t->foreignId('role_id')->on('roles');
            $t->foreignId('user_id')->on('users');
            $t->primary(['role_id', 'user_id']);
        })
        ->build()['tables'];
}

/** Reverse the tables and each table's foreign keys and indexes. */
function shuffledSchema(): array
{
    return array_map(function (array $table): array {
        $table['foreign_keys'] = array_reverse($table['foreign_keys']);
        $table['indexes'] = array_reverse($table['indexes']);

        return $table;
    }, array_reverse(richSchema()));
}

dataset('formats', SchemaExporter::formats());

it('produces byte-identical output on two runs', function (string $format) {
    $exporter = new SchemaExporter;
    $tables = $exporter->tablesFor(richSchema());

    expect($exporter->generate($format, $tables))
        ->toBe($exporter->generate($format, $tables));
})->with('formats');

it('is independent of input ordering', function (string $format) {
    $exporter = new SchemaExporter;

    $fromOriginal = $exporter->generate($format, $exporter->tablesFor(richSchema()));
    $fromShuffled = $exporter->generate($format, $exporter->tablesFor(shuffledSchema()));

    expect($fromShuffled)->toBe($fromOriginal);
})->with('formats');
