<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\MarkdownGenerator;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

/**
 * PHP port of resources/js/markdown-export.js, mirroring
 * tests/js/markdown-export.test.js. Byte parity is pinned by the cross-check.
 */
function markdown(array $tables): string
{
    return (new MarkdownGenerator)->generate($tables);
}

it('renders a heading, the column table, and one row per column', function () {
    $tables = SchemaBuilder::make()->table('users', function ($t) {
        $t->id();
        $t->string('name');
        $t->string('email');
    })->build()['tables'];

    $md = markdown($tables);

    expect($md)->toContain('## users')
        ->and($md)->toContain('| Column | Type | Null | Default | Key |')
        ->and($md)->toContain('| --- | --- | --- | --- | --- |');

    $rows = array_filter(
        explode("\n", $md),
        fn (string $l): bool => str_starts_with($l, '| ') && ! str_contains($l, 'Column') && ! str_contains($l, '---'),
    );
    expect($rows)->toHaveCount(3);
});

it('marks the primary key in the Key column', function () {
    $tables = SchemaBuilder::make()->table('users', fn ($t) => $t->id())->build()['tables'];

    expect(markdown($tables))->toMatch('/\|\s*id\s*\|[^\n]*\|\s*PK\s*\|/');
});

it('shows a composite PK+FK column as "PK, FK"', function () {
    $tables = SchemaBuilder::make()
        ->table('roles', fn ($t) => $t->id())
        ->table('users', fn ($t) => $t->id())
        ->table('role_user', function ($t) {
            $t->foreignId('role_id')->on('roles');
            $t->foreignId('user_id')->on('users');
            $t->primary(['role_id', 'user_id']);
        })->build()['tables'];

    $md = markdown($tables);

    expect($md)->toMatch('/\|\s*role_id\s*\|[^\n]*\|\s*PK, FK\s*\|/')
        ->and($md)->toMatch('/\|\s*user_id\s*\|[^\n]*\|\s*PK, FK\s*\|/');
});

it('renders nullability as yes/no and a default when present', function () {
    $tables = SchemaBuilder::make()->table('t', function ($t) {
        $t->column('active', 'tinyint(1)', nullable: false, default: '1');
        $t->column('note', 'varchar(255)', nullable: true);
    })->build()['tables'];

    $md = markdown($tables);

    expect($md)->toMatch('/\|\s*active\s*\|[^\n]*\|\s*no\s*\|\s*1\s*\|/')
        ->and($md)->toMatch('/\|\s*note\s*\|[^\n]*\|\s*yes\s*\|/');
});

it('lists foreign keys with their referential action', function () {
    $tables = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('posts', function ($t) {
            $t->id();
            $t->foreignId('user_id')->on('users');
        })->build()['tables'];

    // The helper leaves on_delete null; set the action the browser fixture uses.
    $tables[1]['foreign_keys'][0]['on_delete'] = 'cascade';

    $md = markdown($tables);

    expect($md)->toContain('user_id -> users.id')
        ->and($md)->toMatch('/on delete: cascade/');
});

it('lists indexes and flags UNIQUE', function () {
    $tables = SchemaBuilder::make()->table('t', function ($t) {
        $t->string('email');
        $t->unique('email', 't_email_unique');
    })->build()['tables'];

    $md = markdown($tables);

    expect($md)->toContain('t_email_unique (email)')
        ->and($md)->toContain('UNIQUE');
});

it('escapes a pipe inside a value', function () {
    $tables = SchemaBuilder::make()->table('t', function ($t) {
        $t->column('c', "enum('a|b')");
    })->build()['tables'];

    expect(markdown($tables))->toContain("enum('a\\|b')");
});

it('has a top title and one section per table', function () {
    $tables = SchemaBuilder::make()
        ->table('a', fn ($t) => $t->id())
        ->table('b', fn ($t) => $t->id())
        ->build()['tables'];

    $md = markdown($tables);

    expect($md)->toContain('# Data dictionary');
    $sections = array_filter(explode("\n", $md), fn (string $l): bool => str_starts_with($l, '## '));
    expect($sections)->toHaveCount(2);
});

it('renders the title only for an empty schema', function () {
    $md = markdown([]);

    expect($md)->toContain('# Data dictionary')
        ->and($md)->not->toContain('## ');
});
