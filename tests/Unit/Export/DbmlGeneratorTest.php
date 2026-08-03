<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\DbmlGenerator;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

/**
 * PHP port of the browser DBML serializer (resources/js/dbml-export.js). These
 * cases mirror tests/js/dbml-export.test.js so the two implementations stay in
 * step; the byte-for-byte guarantee is pinned separately by the cross-check test.
 */
function dbml(array $tables): string
{
    return (new DbmlGenerator)->generate($tables);
}

it('emits a Table block with a single-column [pk]', function () {
    $tables = SchemaBuilder::make()->table('users', function ($t) {
        $t->id();
        $t->string('name');
    })->build()['tables'];

    $out = dbml($tables);

    expect($out)->toContain('Table users {')
        ->and($out)->toMatch('/id "bigint unsigned" \[pk\]/');
});

it('quotes types with spaces but leaves simple types bare', function () {
    $tables = SchemaBuilder::make()->table('users', function ($t) {
        $t->id();
        $t->string('name');
    })->build()['tables'];

    $out = dbml($tables);

    expect($out)->toContain('"bigint unsigned"')
        ->and($out)->toMatch('/name varchar\(255\)(?!")/');
});

it('renders a composite PK as an indexes block', function () {
    $tables = SchemaBuilder::make()->table('role_user', function ($t) {
        $t->column('role_id', 'bigint unsigned');
        $t->column('user_id', 'bigint unsigned');
        $t->primary(['role_id', 'user_id']);
    })->build()['tables'];

    $out = dbml($tables);

    expect($out)->toContain('indexes {')
        ->and($out)->toContain('(role_id, user_id) [pk]');
});

it('marks non-nullable non-key columns [not null] and leaves nullable ones bare', function () {
    $tables = SchemaBuilder::make()->table('t', function ($t) {
        $t->column('a', 'int', nullable: false);
        $t->column('b', 'int', nullable: true);
    })->build()['tables'];

    $out = dbml($tables);

    expect($out)->toMatch('/a int \[not null\]/')
        ->and($out)->toMatch('/b int(?!\s*\[)/');
});

it('renders a default setting for numbers, keywords, expressions and strings', function () {
    $tables = SchemaBuilder::make()->table('t', function ($t) {
        $t->column('n', 'int', nullable: false, default: '0');
        $t->column('flag', 'boolean', nullable: false, default: 'true');
        $t->column('created', 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP');
        $t->column('label', 'varchar(255)', nullable: false, default: "o'clock");
    })->build()['tables'];

    $out = dbml($tables);

    expect($out)->toContain('default: 0')
        ->and($out)->toContain('default: true')
        ->and($out)->toContain('default: `CURRENT_TIMESTAMP`')
        ->and($out)->toContain("default: 'o\\'clock'");
});

it('emits a Ref per foreign key with the correct direction', function () {
    $tables = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('posts', function ($t) {
            $t->id();
            $t->foreignId('user_id')->on('users');
        })->build()['tables'];

    expect(dbml($tables))->toContain('Ref: posts.user_id > users.id');
});

it('renders a composite foreign key with parenthesised columns', function () {
    $tables = SchemaBuilder::make()
        ->table('x', function ($t) {
            $t->column('a', 'int');
            $t->column('b', 'int');
            $t->foreign('a', 'y', 'c');
        })
        ->table('y', function ($t) {
            $t->column('c', 'int');
            $t->column('d', 'int');
            $t->primary(['c', 'd']);
        })->build()['tables'];

    // Rewrite the single-column FK the helper produced into a composite one.
    $tables[0]['foreign_keys'] = [[
        'name' => 'fk',
        'columns' => ['a', 'b'],
        'references_table' => 'y',
        'references_columns' => ['c', 'd'],
        'on_update' => null,
        'on_delete' => null,
    ]];

    expect(dbml($tables))->toContain('Ref: x.(a, b) > y.(c, d)');
});

it('drops a Ref when the referenced table is absent from the export set', function () {
    $tables = SchemaBuilder::make()->table('posts', function ($t) {
        $t->id();
        $t->foreignId('user_id')->on('users');
    })->build()['tables'];

    expect(dbml($tables))->not->toContain('Ref:');
});

it('keeps an enum as a quoted type with no Enum block', function () {
    $tables = SchemaBuilder::make()->table('posts', function ($t) {
        $t->id();
        $t->column('status', "enum('draft','published','archived')");
    })->build()['tables'];

    $out = dbml($tables);

    expect($out)->toContain('"enum(\'draft\',\'published\',\'archived\')"')
        ->and($out)->not->toContain('Enum ');
});
