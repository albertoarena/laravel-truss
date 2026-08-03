<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\MermaidGenerator;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

/**
 * PHP port of the native-mode path of resources/js/mermaid-definition.js,
 * mirroring tests/js/mermaid-definition.test.js. Byte parity is pinned by the
 * cross-check test.
 */
function mermaid(array $tables): string
{
    return (new MermaidGenerator)->generate($tables);
}

it('opens with the erDiagram keyword and an entity block per table', function () {
    $tables = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('posts', function ($t) {
            $t->id();
            $t->foreignId('user_id')->on('users');
        })->build()['tables'];

    $out = mermaid($tables);

    expect(str_starts_with($out, 'erDiagram'))->toBeTrue()
        ->and($out)->toContain('users {')
        ->and($out)->toContain('posts {');
});

it('derives PK and FK badges from primary_key and foreign_keys', function () {
    $tables = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('posts', function ($t) {
            $t->id();
            $t->foreignId('user_id')->on('users');
        })->build()['tables'];

    $out = mermaid($tables);

    expect($out)->toMatch('/id\s+PK/')
        ->and($out)->toMatch('/user_id\s+FK/');
});

it('marks a column that is both primary and foreign with PK, FK', function () {
    $tables = SchemaBuilder::make()
        ->table('roles', fn ($t) => $t->id())
        ->table('users', fn ($t) => $t->id())
        ->table('role_user', function ($t) {
            $t->foreignId('role_id')->on('roles');
            $t->foreignId('user_id')->on('users');
            $t->primary(['role_id', 'user_id']);
        })->build()['tables'];

    $out = mermaid($tables);

    expect($out)->toMatch('/role_id\s+PK,\s*FK/')
        ->and($out)->toMatch('/user_id\s+PK,\s*FK/');
});

it('sanitizes native types into Mermaid-safe tokens (no spaces or parens)', function () {
    $tables = SchemaBuilder::make()->table('posts', function ($t) {
        $t->id();
        $t->string('title');
    })->build()['tables'];

    $out = mermaid($tables);

    expect($out)->not->toMatch('/bigint unsigned/')
        ->and($out)->not->toMatch('/varchar\(255\)/')
        ->and($out)->toContain('bigint_unsigned')
        ->and($out)->toContain('varchar_255');
});

it('compacts enum/set to the keyword so long value lists do not blow out the column', function () {
    $tables = SchemaBuilder::make()->table('posts', function ($t) {
        $t->id();
        $t->column('status', "enum('draft','published','archived')");
    })->build()['tables'];

    $out = mermaid($tables);

    expect($out)->toMatch('/enum\s+status/')
        ->and($out)->not->toContain('draft')
        ->and($out)->not->toContain('archived');
});

it('emits a crow\'s-foot relationship for a foreign key within the subset', function () {
    $tables = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('posts', function ($t) {
            $t->id();
            $t->foreignId('user_id')->on('users');
        })->build()['tables'];

    $out = mermaid($tables);

    expect($out)->toContain('users ||--o{ posts')
        ->and($out)->toContain('posts_user_id_foreign');
});

it('omits relationships whose referenced table is not in the subset', function () {
    $tables = SchemaBuilder::make()->table('posts', function ($t) {
        $t->id();
        $t->foreignId('user_id')->on('users');
    })->build()['tables'];

    $out = mermaid($tables);

    expect($out)->not->toContain('||--o{')
        ->and($out)->not->toContain('users {');
});

it('shows a self-referential foreign key as a column note, not a self-loop edge', function () {
    $tables = SchemaBuilder::make()->table('categories', function ($t) {
        $t->id();
        $t->foreignId('parent_id')->on('categories');
    })->build()['tables'];

    $out = mermaid($tables);

    expect($out)->not->toContain('categories ||--o{ categories')
        ->and($out)->toMatch('/parent_id\s+FK\s+"self-ref"/');
});
