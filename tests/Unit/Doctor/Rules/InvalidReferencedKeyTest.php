<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Rules\Integrity\InvalidReferencedKey;
use AlbertoArena\Truss\Doctor\Severity;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

it('flags a foreign key that references a column with only a plain index', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id()->string('code', 20)->index('code'))
        ->table('posts', fn ($t) => $t->id()->string('user_code', 20)->foreign('user_code', 'users', 'code'))
        ->build();

    $findings = doctorCheck(new InvalidReferencedKey, $snapshot);

    expect($findings)->toHaveCount(1)
        ->toHaveFinding('TRUSS-INT-008', table: 'posts', column: 'user_code');
    expect($findings[0]->severity)->toBe(Severity::Error);
    expect($findings[0]->message)->toContain('users.code');
});

it('is clean when the foreign key references the primary key', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('posts', fn ($t) => $t->id()->foreignId('user_id')->on('users'))
        ->build();

    expect(doctorCheck(new InvalidReferencedKey, $snapshot))->toBeClean();
});

it('is clean when the foreign key references a unique column', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id()->string('code', 20)->unique('code'))
        ->table('posts', fn ($t) => $t->id()->string('user_code', 20)->foreign('user_code', 'users', 'code'))
        ->build();

    expect(doctorCheck(new InvalidReferencedKey, $snapshot))->toBeClean();
});

it('flags a foreign key that references one column of a composite primary key', function () {
    $snapshot = SchemaBuilder::make()
        ->table('orders', fn ($t) => $t->column('tenant_id', 'bigint unsigned')->column('number', 'int')
            ->primary(['tenant_id', 'number'])->index('tenant_id'))
        ->table('notes', fn ($t) => $t->id()->column('tenant_id', 'bigint unsigned')->foreign('tenant_id', 'orders', 'tenant_id'))
        ->build();

    expect(doctorCheck(new InvalidReferencedKey, $snapshot))
        ->toHaveFinding('TRUSS-INT-008', table: 'notes', column: 'tenant_id');
});

it('flags a composite foreign key whose referenced columns are not unique together', function () {
    $snapshot = SchemaBuilder::make()
        ->table('orders', fn ($t) => $t->id()->column('tenant_id', 'bigint unsigned')->column('number', 'int')
            ->index(['tenant_id', 'number']))
        ->table('lines', fn ($t) => $t->id()->column('tenant_id', 'bigint unsigned')->column('order_number', 'int')
            ->compositeForeign(['tenant_id', 'order_number'], 'orders', ['tenant_id', 'number'], name: 'lines_order_foreign'))
        ->build();

    // A composite key has no single column to mark, so the finding names the constraint.
    expect(doctorCheck(new InvalidReferencedKey, $snapshot))
        ->toHaveFinding('TRUSS-INT-008', table: 'lines', column: 'lines_order_foreign');
});

it('is clean when a composite foreign key matches a unique index listed in another order', function () {
    $snapshot = SchemaBuilder::make()
        ->table('orders', fn ($t) => $t->id()->column('tenant_id', 'bigint unsigned')->column('number', 'int')
            ->unique(['number', 'tenant_id']))
        ->table('lines', fn ($t) => $t->id()->column('tenant_id', 'bigint unsigned')->column('order_number', 'int')
            ->compositeForeign(['tenant_id', 'order_number'], 'orders', ['tenant_id', 'number']))
        ->build();

    expect(doctorCheck(new InvalidReferencedKey, $snapshot))->toBeClean();
});

it('is clean when a composite foreign key matches a composite primary key', function () {
    $snapshot = SchemaBuilder::make()
        ->table('orders', fn ($t) => $t->column('tenant_id', 'bigint unsigned')->column('number', 'int')
            ->primary(['tenant_id', 'number']))
        ->table('lines', fn ($t) => $t->id()->column('tenant_id', 'bigint unsigned')->column('order_number', 'int')
            ->compositeForeign(['order_number', 'tenant_id'], 'orders', ['number', 'tenant_id']))
        ->build();

    expect(doctorCheck(new InvalidReferencedKey, $snapshot))->toBeClean();
});

it('does not count a unique index wider than the referenced columns', function () {
    // unique(tenant_id, code) makes the pair unique, not code on its own.
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id()->column('tenant_id', 'bigint unsigned')->string('code', 20)
            ->unique(['tenant_id', 'code'])->index('code'))
        ->table('posts', fn ($t) => $t->id()->string('user_code', 20)->foreign('user_code', 'users', 'code'))
        ->build();

    expect(doctorCheck(new InvalidReferencedKey, $snapshot))
        ->toHaveFinding('TRUSS-INT-008', table: 'posts', column: 'user_code');
});

it('does not count a unique expression index as covering the plain columns', function () {
    // unique(tenant_id, lower(email)) does not make (tenant_id, email) unique.
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id()->column('tenant_id', 'bigint unsigned')->string('email')
            ->expressionIndex(['tenant_id', ['expression' => 'lower(`email`)']], 'users_tenant_email_unique', unique: true)
            ->index(['tenant_id', 'email']))
        ->table('invites', fn ($t) => $t->id()->column('tenant_id', 'bigint unsigned')->string('email')
            ->compositeForeign(['tenant_id', 'email'], 'users', ['tenant_id', 'email'], name: 'invites_user_foreign'))
        ->build();

    expect(doctorCheck(new InvalidReferencedKey, $snapshot))
        ->toHaveFinding('TRUSS-INT-008', table: 'invites', column: 'invites_user_foreign');
});

it('skips when the referenced table is not in the snapshot', function () {
    $snapshot = SchemaBuilder::make()
        ->table('posts', fn ($t) => $t->id()->string('user_code', 20)->foreign('user_code', 'users', 'code'))
        ->build();

    expect(doctorCheck(new InvalidReferencedKey, $snapshot))->toBeClean();
});

it('runs on SQLite, which accepts the DDL', function () {
    $snapshot = SchemaBuilder::make(driver: 'sqlite')
        ->table('users', fn ($t) => $t->id()->string('code', 20))
        ->table('posts', fn ($t) => $t->id()->string('user_code', 20)->foreign('user_code', 'users', 'code'))
        ->build();

    expect(doctorCheck(new InvalidReferencedKey, $snapshot))
        ->toHaveFinding('TRUSS-INT-008', table: 'posts', column: 'user_code');
});

it('names the columns of an unnamed composite key, as on SQLite', function () {
    $snapshot = SchemaBuilder::make(driver: 'sqlite')
        ->table('orders', fn ($t) => $t->id()->column('tenant_id', 'integer')->column('number', 'integer'))
        ->table('lines', fn ($t) => $t->id()->column('tenant_id', 'integer')->column('order_number', 'integer')
            ->compositeForeign(['tenant_id', 'order_number'], 'orders', ['tenant_id', 'number'], name: ''))
        ->build();

    $findings = doctorCheck(new InvalidReferencedKey, $snapshot);

    expect($findings)->toHaveFinding('TRUSS-INT-008', table: 'lines', column: 'tenant_id, order_number');
    expect($findings[0]->message)->not->toContain('""');
});
