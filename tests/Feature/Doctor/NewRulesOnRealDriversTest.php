<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\DoctorReport;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Introspection\SnapshotBuilder;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * TRUSS-INT-004, INT-008 and TYP-009 against the schema each real driver
 * produces, through the doctor every surface uses. The unit tests prove the
 * logic on the in-memory snapshot; these prove the snapshot a real database
 * returns gives the rules what they expect, on every lane CI runs.
 */

/** @return list<Finding> */
function realDriverFindings(string $code): array
{
    $snapshot = (new SnapshotBuilder)->build('testing');

    return array_values(array_filter(
        (new DoctorReport)->for('testing', $snapshot, preset: 'strict')->all(),
        fn (Finding $finding): bool => $finding->code === $code,
    ));
}

function isMariaDb(): bool
{
    $connection = DB::connection('testing');

    return $connection instanceof MySqlConnection && $connection->isMaria();
}

function isMySql84OrLater(): bool
{
    $connection = DB::connection('testing');

    return $connection instanceof MySqlConnection
        && ! $connection->isMaria()
        && version_compare((string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION), '8.4', '>=');
}

/**
 * The driver table in the rule's plan: PostgreSQL rejects a foreign key onto a
 * non-unique column at DDL time, and so does MySQL 8.4 unless
 * restrict_fk_on_non_standard_key is off. Everywhere else the DDL is accepted.
 */
function rejectsNonUniqueReference(): bool
{
    return DB::connection('testing')->getDriverName() === 'pgsql' || isMySql84OrLater();
}

function createCodesParent(): void
{
    Schema::create('codes', function (Blueprint $table) {
        $table->id();
        $table->string('code', 20)->index();
    });
}

function createCodeChild(): void
{
    Schema::create('usages', function (Blueprint $table) {
        $table->id();
        $table->string('code', 20);
        $table->foreign('code')->references('code')->on('codes');
    });
}

// TRUSS-INT-008

it('flags a foreign key onto a non-unique column where the database accepts it', function () {
    if (rejectsNonUniqueReference()) {
        test()->markTestSkipped('This database rejects the DDL; see the rejection test.');
    }

    createCodesParent();
    createCodeChild();

    $findings = realDriverFindings('TRUSS-INT-008');

    expect($findings)->toHaveFinding('TRUSS-INT-008', table: 'usages', column: 'code');
    expect($findings[0]->hint)->toContain(
        DB::connection('testing')->getDriverName() === 'sqlite' ? 'foreign key mismatch' : 'several parent rows',
    );
});

it('flags it on MySQL 8.4 once restrict_fk_on_non_standard_key is off', function () {
    if (! isMySql84OrLater()) {
        test()->markTestSkipped('Only MySQL 8.4 and later has the setting.');
    }

    createCodesParent();
    DB::connection('testing')->statement('set session restrict_fk_on_non_standard_key = off');
    createCodeChild();

    expect(realDriverFindings('TRUSS-INT-008'))->toHaveFinding('TRUSS-INT-008', table: 'usages', column: 'code');
});

it('cannot hold the violation where the database rejects the DDL', function () {
    if (! rejectsNonUniqueReference()) {
        test()->markTestSkipped('This database accepts the DDL.');
    }

    createCodesParent();

    expect(fn () => createCodeChild())->toThrow(QueryException::class);
});

it('is clean on every driver when the foreign key references a unique column', function () {
    Schema::create('codes', function (Blueprint $table) {
        $table->id();
        $table->string('code', 20)->unique();
    });
    createCodeChild();

    Schema::create('orders', function (Blueprint $table) {
        $table->unsignedBigInteger('tenant_id');
        $table->unsignedInteger('number');
        $table->primary(['tenant_id', 'number']);
    });
    Schema::create('lines', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->unsignedInteger('order_number');
        $table->foreign(['tenant_id', 'order_number'])->references(['tenant_id', 'number'])->on('orders');
    });

    expect(realDriverFindings('TRUSS-INT-008'))->toBeClean();
});

it('does not take a unique expression index for a unique key on its columns', function () {
    if (rejectsNonUniqueReference() || isMariaDb()) {
        test()->markTestSkipped('Needs both expression indexes and a non-unique reference.');
    }

    Schema::create('members', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->string('email');
        $table->index(['tenant_id', 'email']);
    });
    DB::connection('testing')->statement('create unique index members_tenant_email_unique on members (tenant_id, (lower(email)))');
    Schema::create('invites', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->string('email');
        $table->foreign(['tenant_id', 'email'], 'invites_member_foreign')->references(['tenant_id', 'email'])->on('members');
    });

    expect(realDriverFindings('TRUSS-INT-008'))->toHaveFinding('TRUSS-INT-008', table: 'invites');
});

// TRUSS-INT-004

it('flags a foreign key declared twice on the same column', function () {
    Schema::create('authors', function (Blueprint $table) {
        $table->id();
    });
    Schema::create('books', function (Blueprint $table) {
        $table->id();
        $table->foreignId('author_id');
        $table->foreign('author_id', 'books_author_first')->references('id')->on('authors');
        $table->foreign('author_id', 'books_author_second')->references('id')->on('authors');
    });

    $findings = realDriverFindings('TRUSS-INT-004');

    // SQLite reports no constraint names, so the message must not quote an empty one.
    expect($findings)->toHaveCount(1);
    expect($findings[0]->table)->toBe('books');
    expect($findings[0]->message)->not->toContain('""');
});

it('is clean when one column references two different tables', function () {
    Schema::create('authors', function (Blueprint $table) {
        $table->id();
    });
    Schema::create('editors', function (Blueprint $table) {
        $table->id();
    });
    Schema::create('books', function (Blueprint $table) {
        $table->id();
        $table->foreignId('person_id');
        $table->foreign('person_id', 'books_person_author')->references('id')->on('authors');
        $table->foreign('person_id', 'books_person_editor')->references('id')->on('editors');
    });

    expect(realDriverFindings('TRUSS-INT-004'))->toBeClean();
});

// TRUSS-TYP-009

it('flags a uuid, a ulid and a *_uuid foreign key stored as a wider string, except on SQLite', function () {
    Schema::create('accounts', function (Blueprint $table) {
        $table->id();
        $table->string('uuid');
        $table->string('ulid');
        $table->string('legacy_uuid', 191);
        $table->unique('legacy_uuid');
    });
    Schema::create('notes', function (Blueprint $table) {
        $table->id();
        $table->string('account_uuid', 191);
        $table->foreign('account_uuid')->references('legacy_uuid')->on('accounts');
        $table->string('distant_uuid', 256);
    });

    $findings = realDriverFindings('TRUSS-TYP-009');

    if (DB::connection('testing')->getDriverName() === 'sqlite') {
        expect($findings)->toBeClean();

        return;
    }

    expect($findings)->toHaveCount(3)
        ->toHaveFinding('TRUSS-TYP-009', table: 'accounts', column: 'uuid')
        ->toHaveFinding('TRUSS-TYP-009', table: 'accounts', column: 'ulid')
        ->toHaveFinding('TRUSS-TYP-009', table: 'notes', column: 'account_uuid');
});

it('is clean on every driver for the columns uuid() and ulid() create', function () {
    Schema::create('accounts', function (Blueprint $table) {
        $table->id();
        $table->uuid('uuid');
        $table->ulid('public_ulid');
        $table->string('owner_uuid', 36);
    });

    expect(realDriverFindings('TRUSS-TYP-009'))->toBeClean();
});
