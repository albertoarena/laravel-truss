<?php

declare(strict_types=1);

use AlbertoArena\Truss\Introspection\Data\Index;
use AlbertoArena\Truss\Introspection\Data\IndexPart;
use AlbertoArena\Truss\Introspection\SnapshotBuilder;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Laravel's getIndexes() drops the expression part of an index on every driver,
 * so an index on (tenant_id, lower(email)) comes back as a plain index on
 * tenant_id. The snapshot carries the ordered key parts for such an index.
 */

function expressionIndex(string $name): Index
{
    $members = collect((new SnapshotBuilder)->introspect('testing'))->firstWhere('name', 'members');

    return collect($members->indexes)->firstWhere('name', $name);
}

/** MariaDB has no expression indexes, so it cannot create the ones under test. */
function skipOnMariaDb(): void
{
    $connection = DB::connection('testing');

    if ($connection instanceof MySqlConnection && $connection->isMaria()) {
        test()->markTestSkipped('MariaDB has no expression indexes.');
    }
}

/**
 * The expression as each driver prints it back, kept verbatim: Postgres adds
 * the cast it applied to the varchar column, MySQL quotes the column name.
 */
function lowerEmail(): string
{
    return match (DB::connection('testing')->getDriverName()) {
        'mysql' => 'lower(`email`)',
        'pgsql' => 'lower(email::text)',
        default => 'lower(email)',
    };
}

beforeEach(function () {
    Schema::create('members', function ($table) {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->string('email');
        $table->index('email', 'members_email_index');
    });
});

it('reports the ordered parts of an index that mixes a column and an expression', function () {
    skipOnMariaDb();

    DB::statement('create unique index members_tenant_email_unique on members (tenant_id, (lower(email)))');

    $index = expressionIndex('members_tenant_email_unique');

    expect($index->columns)->toBe(['tenant_id'])
        ->and($index->unique)->toBeTrue()
        ->and($index->parts)->toEqual([IndexPart::column('tenant_id'), IndexPart::expression(lowerEmail())]);
});

it('keeps the key order when the expression comes first', function () {
    skipOnMariaDb();

    DB::statement('create index members_email_tenant_index on members ((lower(email)), tenant_id)');

    expect(expressionIndex('members_email_tenant_index')->parts)
        ->toEqual([IndexPart::expression(lowerEmail()), IndexPart::column('tenant_id')]);
});

it('reports an index on an expression alone', function () {
    skipOnMariaDb();

    DB::statement('create index members_email_lower_index on members ((lower(email)))');

    $index = expressionIndex('members_email_lower_index');

    expect($index->columns)->toBe([])
        ->and($index->parts)->toEqual([IndexPart::expression(lowerEmail())]);
});

it('leaves the sort order out of an expression part', function () {
    skipOnMariaDb();

    DB::statement('create index members_email_desc_index on members ((lower(email)) desc)');

    expect(expressionIndex('members_email_desc_index')->parts)->toEqual([IndexPart::expression(lowerEmail())]);
});

it('gives a plain column index no parts', function () {
    expect(expressionIndex('members_email_index')->parts)->toBe([]);
});

it('carries the parts through the SQLite migration replay', function () {
    config()->set('database.default', 'unreachable');
    config()->set('database.connections.unreachable', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 1,
        'database' => 'nope',
        'username' => 'nope',
        'password' => 'nope',
    ]);
    app('migrator')->path(__DIR__.'/../../Fixtures/expression-index-migrations');

    $members = collect((new SnapshotBuilder)->build()['tables'])->firstWhere('name', 'members');
    $index = collect($members['indexes'])->firstWhere('name', 'members_tenant_email_index');

    expect($index['columns'])->toBe(['tenant_id'])
        ->and($index['parts'])->toBe([['column' => 'tenant_id'], ['expression' => 'lower(email)']]);
});
