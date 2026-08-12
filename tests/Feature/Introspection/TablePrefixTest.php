<?php

declare(strict_types=1);

use AlbertoArena\Truss\Introspection\Data\Table;
use AlbertoArena\Truss\Introspection\SnapshotBuilder;
use Illuminate\Support\Facades\DB;

/*
 * Issue #46. When a connection configures a table prefix, getTables() reports the
 * real, prefixed names, but Laravel's schema builder prepends the prefix again to
 * anything passed into getColumns()/getIndexes()/getForeignKeys(). Feeding the
 * reported names straight back in therefore looks up "portal_portal_users", which
 * exists nowhere, and every table comes back structurally empty with no error.
 *
 * Introspection must report the real database names and their full structure
 * whether or not a prefix is configured, and whether or not a given table
 * actually carries it.
 */

const PREFIX = 'portal_';

/**
 * @return array<string, Table>
 */
function introspectPrefixed(): array
{
    return collect((new SnapshotBuilder)->introspect('testing'))->keyBy('name')->all();
}

beforeEach(function () {
    // Rebuild the connection with a prefix, exactly as an app with
    // DB_PREFIX=portal_ in config/database.php would boot it.
    config(['database.connections.testing.prefix' => PREFIX]);
    DB::purge('testing');

    (require __DIR__.'/../../Fixtures/migrations/0001_01_01_000000_create_fixture_tables.php')->up();
});

it('reports the real prefixed table names', function () {
    expect(array_keys(introspectPrefixed()))
        ->toContain(PREFIX.'users', PREFIX.'posts', PREFIX.'post_tag');
});

it('introspects columns, types and defaults on a prefixed table', function () {
    $posts = collect(introspectPrefixed()[PREFIX.'posts']->columns)->keyBy('name');

    expect($posts)->not->toBeEmpty()
        ->and($posts['title']->type)->toBeString()->not->toBeEmpty()
        ->and($posts['title']->nullable)->toBeFalse()
        ->and($posts['body']->nullable)->toBeTrue()
        ->and($posts['status']->default)->not->toBeNull();
});

it('introspects the primary key on a prefixed table', function () {
    expect(introspectPrefixed()[PREFIX.'users']->primaryKey)->toBe(['id'])
        ->and(introspectPrefixed()[PREFIX.'post_tag']->primaryKey)->toBe(['post_id', 'tag_id']);
});

it('introspects indexes on a prefixed table', function () {
    $emailIndex = collect(introspectPrefixed()[PREFIX.'users']->indexes)
        ->firstWhere('columns', ['email']);

    expect($emailIndex)->not->toBeNull()
        ->and($emailIndex->unique)->toBeTrue();
});

it('introspects foreign keys whose targets match the reported table names', function () {
    // The diagram draws an edge by matching referencesTable against a table name,
    // so both sides have to carry the prefix or the relationship line is dropped.
    $fk = collect(introspectPrefixed()[PREFIX.'posts']->foreignKeys)
        ->firstWhere('referencesTable', PREFIX.'users');

    expect($fk)->not->toBeNull()
        ->and($fk->columns)->toBe(['user_id'])
        ->and($fk->referencesColumns)->toBe(['id'])
        ->and(strtolower((string) $fk->onDelete))->toBe('cascade');
});

it('introspects a table in the same schema that does not carry the prefix', function () {
    // A prefix is often used to share one database between apps, so the schema can
    // hold tables the prefix does not apply to. Stripping the prefix from the
    // reported name is not enough on its own: these tables have nothing to strip
    // and would still be looked up as "portal_legacy_audit".
    DB::connection('testing')->statement('create table legacy_audit (id integer)');

    $legacy = introspectPrefixed()['legacy_audit'] ?? null;

    expect($legacy)->not->toBeNull()
        ->and(collect($legacy->columns)->pluck('name')->all())->toBe(['id']);
});

it('leaves the connection prefix untouched after introspecting', function () {
    (new SnapshotBuilder)->introspect('testing');

    expect(DB::connection('testing')->getTablePrefix())->toBe(PREFIX);
});

it('still builds a serialized snapshot on a prefixed connection', function () {
    $snapshot = (new SnapshotBuilder)->build('testing');

    $users = collect($snapshot['tables'])->firstWhere('name', PREFIX.'users');

    expect($snapshot['fallback'])->toBeFalse()
        ->and($users['columns'])->not->toBeEmpty();
});
