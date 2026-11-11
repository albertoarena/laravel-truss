<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Confidence;
use AlbertoArena\Truss\Doctor\Rules\Type\UuidAsVarchar;
use AlbertoArena\Truss\Doctor\Severity;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

it('is a heuristic warning', function () {
    $rule = new UuidAsVarchar;

    expect($rule->confidence())->toBe(Confidence::Heuristic);
    expect($rule->defaultSeverity())->toBe(Severity::Warning);
});

it('flags a uuid column stored as varchar(255) on MySQL, pointing at char(36)', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id()->string('uuid'))
        ->build();

    $findings = doctorCheck(new UuidAsVarchar, $snapshot);

    expect($findings)->toHaveCount(1)
        ->toHaveFinding('TRUSS-TYP-009', table: 'users', column: 'uuid');
    expect($findings[0]->hint)->toContain('char(36)');
});

it('flags a uuid column on MariaDB, pointing at its native uuid type', function () {
    $snapshot = SchemaBuilder::make(driver: 'mariadb')
        ->table('users', fn ($t) => $t->id()->string('uuid'))
        ->build();

    $findings = doctorCheck(new UuidAsVarchar, $snapshot);

    expect($findings)->toHaveFinding('TRUSS-TYP-009', table: 'users', column: 'uuid');
    expect($findings[0]->hint)->toContain('native uuid type')->toContain('10.7');
});

it('flags any varchar wider than the identifier, such as the 191 default', function (string $name, int $length) {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id()->string($name, $length))
        ->build();

    expect(doctorCheck(new UuidAsVarchar, $snapshot))->toHaveFinding('TRUSS-TYP-009', table: 'users', column: $name);
})->with([
    'uuid varchar(191)' => ['uuid', 191],
    'uuid varchar(40)' => ['uuid', 40],
    'ulid varchar(36)' => ['ulid', 36],
]);

it('flags a varchar(191) uuid on PostgreSQL', function () {
    $snapshot = SchemaBuilder::make(driver: 'pgsql')
        ->table('posts', fn ($t) => $t->id()->column('uuid', 'character varying(191)'))
        ->build();

    expect(doctorCheck(new UuidAsVarchar, $snapshot))->toHaveFinding('TRUSS-TYP-009', table: 'posts', column: 'uuid');
});

it('flags a *_ulid foreign key column on MariaDB, pointing at char(26)', function () {
    $snapshot = SchemaBuilder::make(driver: 'mariadb')
        ->table('customers', fn ($t) => $t->column('ulid', 'char(26)')->primary(['ulid']))
        ->table('orders', fn ($t) => $t->id()->string('customer_ulid')->foreign('customer_ulid', 'customers', 'ulid'))
        ->build();

    $findings = doctorCheck(new UuidAsVarchar, $snapshot);

    expect($findings)->toHaveFinding('TRUSS-TYP-009', table: 'orders', column: 'customer_ulid');
    expect($findings[0]->hint)->toContain('char(26)');
});

it('flags a *_uuid foreign key column on PostgreSQL, pointing at the native uuid type', function () {
    $snapshot = SchemaBuilder::make(driver: 'pgsql')
        ->table('authors', fn ($t) => $t->column('uuid', 'uuid')->primary(['uuid']))
        ->table('posts', fn ($t) => $t->id()->column('author_uuid', 'character varying(255)')
            ->foreign('author_uuid', 'authors', 'uuid'))
        ->build();

    $findings = doctorCheck(new UuidAsVarchar, $snapshot);

    expect($findings)->toHaveFinding('TRUSS-TYP-009', table: 'posts', column: 'author_uuid');
    expect($findings[0]->hint)->toContain('native uuid type');
});

it('flags a ulid column on PostgreSQL, pointing at char(26)', function () {
    $snapshot = SchemaBuilder::make(driver: 'pgsql')
        ->table('orders', fn ($t) => $t->id()->column('ulid', 'character varying(255)'))
        ->build();

    $findings = doctorCheck(new UuidAsVarchar, $snapshot);

    expect($findings)->toHaveFinding('TRUSS-TYP-009', table: 'orders', column: 'ulid');
    expect($findings[0]->hint)->toContain('char(26)');
});

it('is clean when the column is already sized', function (string $driver, string $type) {
    $snapshot = SchemaBuilder::make(driver: $driver)
        ->table('users', fn ($t) => $t->id()->column('uuid', $type))
        ->build();

    expect(doctorCheck(new UuidAsVarchar, $snapshot))->toBeClean();
})->with([
    'mysql char(36)' => ['mysql', 'char(36)'],
    'mysql varchar(36)' => ['mysql', 'varchar(36)'],
    'mysql varchar(32)' => ['mysql', 'varchar(32)'],
    'mysql char(40)' => ['mysql', 'char(40)'],
    'pgsql uuid' => ['pgsql', 'uuid'],
]);

it('skips SQLite, where every string reads back as bare varchar', function () {
    $snapshot = SchemaBuilder::make(driver: 'sqlite')
        ->table('users', fn ($t) => $t->id()->column('uuid', 'varchar'))
        ->build();

    expect(doctorCheck(new UuidAsVarchar, $snapshot))->toBeClean();
});

it('is clean for a ulid stored as varchar(26)', function () {
    $snapshot = SchemaBuilder::make()
        ->table('orders', fn ($t) => $t->id()->string('ulid', 26))
        ->build();

    expect(doctorCheck(new UuidAsVarchar, $snapshot))->toBeClean();
});

it('does not flag a name that only contains uuid or ulid', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id()->string('uuid_source')->string('fluid'))
        ->build();

    expect(doctorCheck(new UuidAsVarchar, $snapshot))->toBeClean();
});

it('does not flag a *_uuid or *_ulid column that is not a foreign key', function () {
    // Field study, 11/11/2026: every column of this shape held somebody else's
    // identifier (a CardDAV UID, an author-written blueprint handle), which is
    // not a UUID and is wide on purpose.
    $snapshot = SchemaBuilder::make()
        ->table('contacts', fn ($t) => $t->id()->string('distant_uuid', 256)->string('public_ulid'))
        ->build();

    expect(doctorCheck(new UuidAsVarchar, $snapshot))->toBeClean();
});

it('flags a *_uuid column inside a composite foreign key', function () {
    $snapshot = SchemaBuilder::make()
        ->table('tenants', fn ($t) => $t->id()->column('uuid', 'char(36)')->unique(['id', 'uuid']))
        ->table('posts', fn ($t) => $t->id()->integer('tenant_id')->string('tenant_uuid')
            ->compositeForeign(['tenant_id', 'tenant_uuid'], 'tenants', ['id', 'uuid']))
        ->build();

    expect(doctorCheck(new UuidAsVarchar, $snapshot))
        ->toHaveCount(1)
        ->toHaveFinding('TRUSS-TYP-009', table: 'posts', column: 'tenant_uuid');
});
