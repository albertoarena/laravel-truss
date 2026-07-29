<?php

declare(strict_types=1);

use AlbertoArena\Truss\Diff\SchemaDiffer;

// The differ is pure: it compares two already-serialized snapshots (the shape
// SchemaSerializer produces) and returns a plain diff array. No database, no
// framework, no I/O. These fixtures build that array shape directly.

function sdCol(string $name, string $type = 'varchar(255)', bool $nullable = false, mixed $default = null): array
{
    return ['name' => $name, 'type' => $type, 'nullable' => $nullable, 'default' => $default];
}

function sdTable(string $name, array $columns = [], array $primaryKey = [], array $indexes = [], array $foreignKeys = []): array
{
    return [
        'name' => $name,
        'columns' => $columns,
        'primary_key' => $primaryKey,
        'indexes' => $indexes,
        'foreign_keys' => $foreignKeys,
    ];
}

function sdSnap(array $tables, ?string $generatedAt = '2026-07-29T10:00:00+00:00'): array
{
    return ['connection' => 'testing', 'generated_at' => $generatedAt, 'tables' => $tables];
}

function sdChangedTable(array $diff, string $name): ?array
{
    foreach ($diff['tables_changed'] as $table) {
        if ($table['name'] === $name) {
            return $table;
        }
    }

    return null;
}

it('reports no changes for identical snapshots', function () {
    $users = sdTable('users', [sdCol('id', 'bigint unsigned'), sdCol('email')], ['id']);
    $snap = sdSnap([$users]);

    $diff = (new SchemaDiffer)->diff($snap, $snap);

    expect($diff['has_changes'])->toBeFalse()
        ->and($diff['tables_added'])->toBe([])
        ->and($diff['tables_removed'])->toBe([])
        ->and($diff['tables_changed'])->toBe([]);
});

it('echoes the two envelope timestamps', function () {
    $baseline = sdSnap([], '2026-07-01T00:00:00+00:00');
    $current = sdSnap([], '2026-07-29T00:00:00+00:00');

    $diff = (new SchemaDiffer)->diff($baseline, $current);

    expect($diff['baseline_generated_at'])->toBe('2026-07-01T00:00:00+00:00')
        ->and($diff['current_generated_at'])->toBe('2026-07-29T00:00:00+00:00');
});

it('detects an added table', function () {
    $baseline = sdSnap([sdTable('users', [sdCol('id')], ['id'])]);
    $current = sdSnap([
        sdTable('users', [sdCol('id')], ['id']),
        sdTable('posts', [sdCol('id')], ['id']),
    ]);

    $diff = (new SchemaDiffer)->diff($baseline, $current);

    expect($diff['has_changes'])->toBeTrue()
        ->and($diff['tables_added'])->toBe([['name' => 'posts']])
        ->and($diff['tables_removed'])->toBe([])
        ->and($diff['tables_changed'])->toBe([]);
});

it('detects a removed table', function () {
    $baseline = sdSnap([
        sdTable('users', [sdCol('id')], ['id']),
        sdTable('legacy', [sdCol('id')], ['id']),
    ]);
    $current = sdSnap([sdTable('users', [sdCol('id')], ['id'])]);

    $diff = (new SchemaDiffer)->diff($baseline, $current);

    expect($diff['tables_removed'])->toBe([['name' => 'legacy']])
        ->and($diff['tables_added'])->toBe([]);
});

it('treats every current table as added when the baseline is empty', function () {
    $current = sdSnap([sdTable('users', [sdCol('id')], ['id']), sdTable('posts')]);

    $diff = (new SchemaDiffer)->diff(sdSnap([]), $current);

    expect($diff['tables_added'])->toBe([['name' => 'users'], ['name' => 'posts']])
        ->and($diff['has_changes'])->toBeTrue();
});

it('detects an added column with its full definition', function () {
    $baseline = sdSnap([sdTable('users', [sdCol('id', 'bigint unsigned')], ['id'])]);
    $current = sdSnap([sdTable('users', [
        sdCol('id', 'bigint unsigned'),
        sdCol('email', 'varchar(255)', true, null),
    ], ['id'])]);

    $diff = (new SchemaDiffer)->diff($baseline, $current);
    $users = sdChangedTable($diff, 'users');

    expect($users)->not->toBeNull()
        ->and($users['columns_added'])->toBe([
            ['name' => 'email', 'type' => 'varchar(255)', 'nullable' => true, 'default' => null],
        ])
        ->and($users['columns_removed'])->toBe([])
        ->and($users['columns_changed'])->toBe([]);
});

it('detects a removed column by name', function () {
    $baseline = sdSnap([sdTable('users', [sdCol('id'), sdCol('nickname')], ['id'])]);
    $current = sdSnap([sdTable('users', [sdCol('id')], ['id'])]);

    $users = sdChangedTable((new SchemaDiffer)->diff($baseline, $current), 'users');

    expect($users['columns_removed'])->toBe([['name' => 'nickname']]);
});

it('detects a column type change with before and after', function () {
    $baseline = sdSnap([sdTable('users', [sdCol('id'), sdCol('votes', 'integer')], ['id'])]);
    $current = sdSnap([sdTable('users', [sdCol('id'), sdCol('votes', 'bigint')], ['id'])]);

    $users = sdChangedTable((new SchemaDiffer)->diff($baseline, $current), 'users');

    expect($users['columns_changed'])->toBe([
        ['name' => 'votes', 'changes' => ['type' => ['before' => 'integer', 'after' => 'bigint']]],
    ]);
});

it('detects nullable and default changes, only for the fields that moved', function () {
    $baseline = sdSnap([sdTable('users', [sdCol('status', 'varchar(255)', false, 'draft')])]);
    $current = sdSnap([sdTable('users', [sdCol('status', 'varchar(255)', true, 'active')])]);

    $users = sdChangedTable((new SchemaDiffer)->diff($baseline, $current), 'users');

    expect($users['columns_changed'])->toBe([
        ['name' => 'status', 'changes' => [
            'nullable' => ['before' => false, 'after' => true],
            'default' => ['before' => 'draft', 'after' => 'active'],
        ]],
    ]);
});

it('does not list an unchanged table under tables_changed', function () {
    $users = sdTable('users', [sdCol('id')], ['id']);
    $baseline = sdSnap([$users, sdTable('posts', [sdCol('id')], ['id'])]);
    $current = sdSnap([$users, sdTable('posts', [sdCol('id'), sdCol('title')], ['id'])]);

    $diff = (new SchemaDiffer)->diff($baseline, $current);

    expect($diff['tables_changed'])->toHaveCount(1)
        ->and($diff['tables_changed'][0]['name'])->toBe('posts')
        ->and(sdChangedTable($diff, 'users'))->toBeNull();
});

it('detects added, removed and changed indexes including composite columns', function () {
    $baseline = sdSnap([sdTable('users', [sdCol('id'), sdCol('email'), sdCol('team_id')], ['id'], [
        ['name' => 'users_email_index', 'columns' => ['email'], 'unique' => false],
        ['name' => 'users_stale_index', 'columns' => ['team_id'], 'unique' => false],
    ])]);
    $current = sdSnap([sdTable('users', [sdCol('id'), sdCol('email'), sdCol('team_id')], ['id'], [
        ['name' => 'users_email_index', 'columns' => ['email'], 'unique' => true],
        ['name' => 'users_team_id_name_index', 'columns' => ['team_id', 'email'], 'unique' => false],
    ])]);

    $users = sdChangedTable((new SchemaDiffer)->diff($baseline, $current), 'users');

    expect($users['indexes_added'])->toBe([
        ['name' => 'users_team_id_name_index', 'columns' => ['team_id', 'email'], 'unique' => false],
    ])
        ->and($users['indexes_removed'])->toBe([['name' => 'users_stale_index']])
        ->and($users['indexes_changed'])->toBe([
            ['name' => 'users_email_index', 'changes' => ['unique' => ['before' => false, 'after' => true]]],
        ]);
});

it('detects added, removed and changed foreign keys including composite columns', function () {
    $fk = fn (string $name, array $cols, string $refTable, array $refCols, string $onDelete = 'cascade'): array => [
        'name' => $name,
        'columns' => $cols,
        'references_table' => $refTable,
        'references_columns' => $refCols,
        'on_update' => 'no action',
        'on_delete' => $onDelete,
    ];

    $baseline = sdSnap([sdTable('posts', [sdCol('id'), sdCol('user_id'), sdCol('team_id')], ['id'], [], [
        $fk('posts_user_id_foreign', ['user_id'], 'users', ['id'], 'cascade'),
        $fk('posts_team_id_foreign', ['team_id'], 'teams', ['id'], 'cascade'),
    ])]);
    $current = sdSnap([sdTable('posts', [sdCol('id'), sdCol('user_id'), sdCol('team_id')], ['id'], [], [
        $fk('posts_user_id_foreign', ['user_id'], 'users', ['id'], 'set null'),
        $fk('posts_team_slug_foreign', ['team_id', 'user_id'], 'memberships', ['team_id', 'user_id'], 'cascade'),
    ])]);

    $posts = sdChangedTable((new SchemaDiffer)->diff($baseline, $current), 'posts');

    expect($posts['foreign_keys_added'])->toBe([
        $fk('posts_team_slug_foreign', ['team_id', 'user_id'], 'memberships', ['team_id', 'user_id'], 'cascade'),
    ])
        ->and($posts['foreign_keys_removed'])->toBe([['name' => 'posts_team_id_foreign']])
        ->and($posts['foreign_keys_changed'])->toBe([
            ['name' => 'posts_user_id_foreign', 'changes' => ['on_delete' => ['before' => 'cascade', 'after' => 'set null']]],
        ]);
});

it('detects a primary key change', function () {
    $baseline = sdSnap([sdTable('sessions', [sdCol('id'), sdCol('token')], ['id'])]);
    $current = sdSnap([sdTable('sessions', [sdCol('id'), sdCol('token')], ['id', 'token'])]);

    $sessions = sdChangedTable((new SchemaDiffer)->diff($baseline, $current), 'sessions');

    expect($sessions['changes'] ?? null)->toBe(['primary_key' => ['before' => ['id'], 'after' => ['id', 'token']]]);
});
