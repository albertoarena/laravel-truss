<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\DbmlGenerator;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

/**
 * The DBML serializer, the only implementation since the browser download moved
 * to the gated export route.
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

it('quotes table and column names DBML cannot read bare, everywhere they appear', function () {
    $tables = SchemaBuilder::make()
        ->table('t94 users', function ($t) {
            $t->column('id', 'int', nullable: false);
            $t->column('full name', 'int');
            $t->column('città', 'int');
            $t->column('a$b', 'int');
            $t->primary(['id', 'full name']);
            $t->unique(['a$b']);
        })
        ->table('sort direction LR', function ($t) {
            $t->id();
            $t->column('user id', 'int');
            $t->foreign('user id', 't94 users', 'id');
        })->build()['tables'];

    $out = dbml($tables);

    expect($out)->toContain('Table "t94 users" {')
        ->and($out)->toContain('  "full name" int')
        ->and($out)->toContain('  "città" int')
        ->and($out)->toContain('  "a$b" int')
        ->and($out)->toContain('    (id, "full name") [pk]')
        ->and($out)->toContain('    "a$b" [unique]')
        ->and($out)->toContain('Table "sort direction LR" {')
        ->and($out)->toContain('Ref: "sort direction LR"."user id" > "t94 users".id');
});

it('leaves names DBML reads bare unchanged, a leading digit included', function () {
    $tables = SchemaBuilder::make()
        ->table('429_logs', function ($t) {
            $t->id();
            $t->column('429_error_date', 'date');
        })->build()['tables'];

    $out = dbml($tables);

    expect($out)->toContain('Table 429_logs {')
        ->and($out)->toContain('  429_error_date date')
        ->and($out)->not->toContain('"429');
});

// DBML has no escape for a double quote or a line break inside a quoted name
// (@dbml/core 11 rejects `\"`, a doubled quote, and a newline alike), so those
// fall back to `_`, the same fallback the Mermaid export uses for a backtick.
it('writes a quote or line break DBML cannot hold as an underscore', function () {
    $tables = SchemaBuilder::make()
        ->table('50% "off"', function ($t) {
            $t->id();
            $t->column("two\nlines", 'int');
        })->build()['tables'];

    $out = dbml($tables);

    expect($out)->toContain('Table "50% _off_" {')
        ->and($out)->toContain('  "two_lines" int');
});
