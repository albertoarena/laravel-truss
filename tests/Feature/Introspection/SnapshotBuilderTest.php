<?php

declare(strict_types=1);

use AlbertoArena\Truss\Introspection\Data\Table;
use AlbertoArena\Truss\Introspection\SnapshotBuilder;
use Illuminate\Support\Facades\DB;

/**
 * @return array<string, Table>
 */
function introspectFixtures(): array
{
    $tables = (new SnapshotBuilder)->introspect('testing');

    return collect($tables)->keyBy('name')->all();
}

beforeEach(function () {
    (require __DIR__.'/../../Fixtures/migrations/0001_01_01_000000_create_fixture_tables.php')->up();
});

it('introspects every fixture table from the live connection', function () {
    expect(array_keys(introspectFixtures()))
        ->toContain('users', 'posts', 'tags', 'post_tag', 'categories', 'regions', 'region_stats', 'logs');
});

it('scopes introspection to the connection schema and ignores other databases on the server', function () {
    // Reproduces issue #3: on a server hosting several databases (a shared local
    // MySQL, or here a second attached SQLite schema), an unscoped getTables() lists
    // every schema's tables, so foreign tables leak into the snapshot. Truss must
    // only introspect the tables that belong to the target connection's own schema.
    $connection = DB::connection('testing');
    $connection->statement("ATTACH DATABASE ':memory:' AS other_app");
    $connection->statement('CREATE TABLE other_app.ghost (id integer primary key)');

    $names = array_keys(introspectFixtures());

    expect($names)->toContain('users')      // our own schema is still fully introspected
        ->and($names)->not->toContain('ghost'); // a table from another database must not leak in
});

it('reports the primary key, hoisted out of the index list', function () {
    $users = introspectFixtures()['users'];

    expect($users->primaryKey)->toBe(['id'])
        ->and(collect($users->indexes)->pluck('columns')->flatten())->not->toContain('id');
});

it('reports a composite primary key on a pivot table', function () {
    expect(introspectFixtures()['post_tag']->primaryKey)->toBe(['post_id', 'tag_id']);
});

it('reports an empty primary key for a key-less table', function () {
    expect(introspectFixtures()['logs']->primaryKey)->toBe([]);
});

it('reports native column types, nullability and defaults', function () {
    $posts = collect(introspectFixtures()['posts']->columns)->keyBy('name');

    expect($posts['title']->type)->toBeString()->not->toBeEmpty()
        ->and($posts['body']->nullable)->toBeTrue()
        ->and($posts['body']->default)->toBeNull()
        ->and($posts['status']->default)->not->toBeNull()
        ->and($posts['title']->nullable)->toBeFalse();
});

it('reports a single-column foreign key with its referential action', function () {
    $fks = introspectFixtures()['posts']->foreignKeys;

    $userFk = collect($fks)->firstWhere('referencesTable', 'users');

    expect($userFk->columns)->toBe(['user_id'])
        ->and($userFk->referencesColumns)->toBe(['id'])
        ->and(strtolower((string) $userFk->onDelete))->toBe('cascade');
});

it('reports a self-referential foreign key', function () {
    $fk = collect(introspectFixtures()['categories']->foreignKeys)->first();

    expect($fk->columns)->toBe(['parent_id'])
        ->and($fk->referencesTable)->toBe('categories');
});

it('reports a composite foreign key', function () {
    $fk = collect(introspectFixtures()['region_stats']->foreignKeys)
        ->firstWhere('referencesTable', 'regions');

    expect($fk->columns)->toBe(['country_code', 'region_code'])
        ->and($fk->referencesColumns)->toBe(['country_code', 'region_code']);
});

it('reports single and composite indexes', function () {
    $userIndexes = introspectFixtures()['users']->indexes;
    $emailIndex = collect($userIndexes)->firstWhere('columns', ['email']);

    expect($emailIndex->unique)->toBeTrue();

    $postIndexes = introspectFixtures()['posts']->indexes;
    $composite = collect($postIndexes)->firstWhere('columns', ['user_id', 'published']);

    expect($composite)->not->toBeNull()
        ->and($composite->unique)->toBeFalse();
});

it('builds a serialized snapshot without an envelope timestamp', function () {
    $snapshot = (new SnapshotBuilder)->build('testing');

    expect($snapshot['connection'])->toBe('testing')
        ->and($snapshot['fallback'])->toBeFalse()
        ->and($snapshot['tables'])->toBeArray()
        ->and($snapshot)->not->toHaveKey('generated_at');
});
