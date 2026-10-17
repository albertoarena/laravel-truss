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

/*
 * MySQL and MariaDB describe the same column in different words. MariaDB keeps
 * the integer display width MySQL 8 dropped, quotes a string default MySQL
 * leaves bare, writes a missing default as the text NULL, and spells the
 * current-time default as a call. Diffing a MariaDB staging database against
 * MySQL production reported about 70 of these on a stock WordPress schema,
 * which buries the handful of changes that are real.
 *
 * The comparison ignores the spelling; the diff still reports each side's own
 * words when something did change, so a reader sees what each server says.
 */
function sdTypeDiff(string $before, string $after): ?array
{
    return sdChangedTable((new SchemaDiffer)->diff(
        sdSnap([sdTable('posts', [sdCol('id', $before)])]),
        sdSnap([sdTable('posts', [sdCol('id', $after)])]),
    ), 'posts');
}

function sdDefaultDiff(mixed $before, mixed $after, string $type = 'varchar(20)'): ?array
{
    return sdChangedTable((new SchemaDiffer)->diff(
        sdSnap([sdTable('posts', [sdCol('status', $type, true, $before)])]),
        sdSnap([sdTable('posts', [sdCol('status', $type, true, $after)])]),
    ), 'posts');
}

it('ignores an integer display width that only one server prints', function (string $mariadb, string $mysql) {
    expect(sdTypeDiff($mariadb, $mysql))->toBeNull()
        ->and(sdTypeDiff($mysql, $mariadb))->toBeNull();
})->with([
    ['int(11)', 'int'],
    ['bigint(20) unsigned', 'bigint unsigned'],
    ['bigint(10)', 'bigint'],
    ['smallint(6)', 'smallint'],
    ['mediumint(9)', 'mediumint'],
    ['tinyint(4)', 'tinyint'],
    ['INT(11)', 'int'],
]);

it('still reports a real type change, in each server\'s own words', function () {
    $table = sdTypeDiff('int(11)', 'bigint unsigned');

    expect($table['columns_changed'])->toBe([
        ['name' => 'id', 'changes' => ['type' => ['before' => 'int(11)', 'after' => 'bigint unsigned']]],
    ]);
});

it('keeps the widths that change what a column means', function (string $before, string $after) {
    // tinyint(1) is how both servers say boolean, and Laravel reads it that
    // way. A zerofill width decides how the value is padded. Neither is
    // spelling, so neither is ignored.
    expect(sdTypeDiff($before, $after))->not->toBeNull();
})->with([
    ['tinyint(1)', 'tinyint'],
    ['int(5) unsigned zerofill', 'int(8) unsigned zerofill'],
    ['varchar(191)', 'varchar(255)'],
    ['decimal(8,2)', 'decimal(10,2)'],
]);

it('ignores the quotes MariaDB puts around a string default', function (mixed $mariadb, mixed $mysql) {
    expect(sdDefaultDiff($mariadb, $mysql))->toBeNull()
        ->and(sdDefaultDiff($mysql, $mariadb))->toBeNull();
})->with([
    ["'publish'", 'publish'],
    ["''", ''],
    ["'0000-00-00 00:00:00'", '0000-00-00 00:00:00'],
    ["'1'", '1'],
    ["'it''s'", "it's"],
]);

it('treats the text NULL as no default', function () {
    expect(sdDefaultDiff('NULL', null))->toBeNull()
        ->and(sdDefaultDiff(null, 'NULL'))->toBeNull()
        ->and(sdDefaultDiff('null', null))->toBeNull();
});

it('keeps a quoted NULL, which is a string default and not a missing one', function () {
    expect(sdDefaultDiff("'NULL'", null))->not->toBeNull();
});

it('ignores how each server spells the current-time default', function (string $mariadb, string $mysql) {
    expect(sdDefaultDiff($mariadb, $mysql, 'timestamp'))->toBeNull();
})->with([
    ['current_timestamp()', 'CURRENT_TIMESTAMP'],
    ['current_timestamp(6)', 'CURRENT_TIMESTAMP(6)'],
]);

it('still reports a real default change, in each server\'s own words', function () {
    $table = sdDefaultDiff("'draft'", 'publish');

    expect($table['columns_changed'])->toBe([
        ['name' => 'status', 'changes' => ['default' => ['before' => "'draft'", 'after' => 'publish']]],
    ]);
});

it('does not unquote half a value', function () {
    // A default that only starts or ends with a quote, or holds two literals,
    // is not one quoted string, so it is compared exactly as written.
    expect(sdDefaultDiff("'a' || 'b'", "a' || 'b"))->not->toBeNull()
        ->and(sdDefaultDiff("'open", 'open'))->not->toBeNull();
});
