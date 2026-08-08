<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\CompactTransform;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

function verboseTables(): array
{
    return SchemaBuilder::make()
        ->table('users', function ($t) {
            $t->id();
            $t->column('status', 'varchar(20)', default: 'active');
            $t->string('email');
            $t->unique('email', 'users_email_unique');
            $t->index('status', 'users_status_index');
            $t->foreignId('team_id')->on('teams');
        })
        ->table('teams', fn ($t) => $t->id())
        ->build()['tables'];
}

it('drops column defaults', function () {
    $tables = (new CompactTransform)->apply(verboseTables());
    $status = collect($tables[0]['columns'])->firstWhere('name', 'status');

    expect($status)->not->toHaveKey('default');
});

it('drops non-unique indexes and keeps unique ones as unnamed markers', function () {
    $tables = (new CompactTransform)->apply(verboseTables());
    $indexes = $tables[0]['indexes'];

    expect($indexes)->toHaveCount(1)
        ->and($indexes[0]['unique'])->toBeTrue()
        ->and($indexes[0]['columns'])->toBe(['email'])
        ->and($indexes[0]['name'])->toBe('');
});

it('keeps every table, column, primary key, and foreign key', function () {
    $full = verboseTables();
    $compact = (new CompactTransform)->apply($full);

    expect(array_column($compact, 'name'))->toBe(array_column($full, 'name'))
        ->and(array_column($compact[0]['columns'], 'name'))->toBe(array_column($full[0]['columns'], 'name'))
        ->and($compact[0]['primary_key'])->toBe($full[0]['primary_key'])
        ->and($compact[0]['foreign_keys'])->toBe($full[0]['foreign_keys']);
});

it('preserves annotations that were already attached', function () {
    $tables = verboseTables();
    $tables[0]['annotation'] = 'a table note';
    $tables[0]['columns'][0]['annotation'] = 'a column note';

    $compact = (new CompactTransform)->apply($tables);

    expect($compact[0]['annotation'])->toBe('a table note')
        ->and($compact[0]['columns'][0]['annotation'])->toBe('a column note');
});
