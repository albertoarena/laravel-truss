<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Rules\Integrity\DuplicateForeignKey;
use AlbertoArena\Truss\Doctor\Severity;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

it('flags a foreign key declared twice on the same column', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('posts', fn ($t) => $t->id()->foreignId('user_id')->on('users')
            ->foreign('user_id', 'users', name: 'posts_user_id_foreign_2'))
        ->build();

    $findings = doctorCheck(new DuplicateForeignKey, $snapshot);

    expect($findings)->toHaveCount(1)
        ->toHaveFinding('TRUSS-INT-004', table: 'posts', column: 'posts_user_id_foreign_2');
    expect($findings[0]->severity)->toBe(Severity::Warning);
    expect($findings[0]->message)->toContain('posts_user_id_foreign');
});

it('flags a composite foreign key declared twice', function () {
    $snapshot = SchemaBuilder::make()
        ->table('orders', fn ($t) => $t->column('tenant_id', 'bigint unsigned')->column('number', 'int')->primary(['tenant_id', 'number']))
        ->table('lines', fn ($t) => $t->id()->column('tenant_id', 'bigint unsigned')->column('order_number', 'int')
            ->compositeForeign(['tenant_id', 'order_number'], 'orders', ['tenant_id', 'number'], name: 'lines_order_a')
            ->compositeForeign(['tenant_id', 'order_number'], 'orders', ['tenant_id', 'number'], name: 'lines_order_b'))
        ->build();

    expect(doctorCheck(new DuplicateForeignKey, $snapshot))
        ->toHaveCount(1)
        ->toHaveFinding('TRUSS-INT-004', table: 'lines', column: 'lines_order_b');
});

it('treats a composite key listed in another order as the same constraint', function () {
    // (a, b) -> (x, y) and (b, a) -> (y, x) pair the same columns, so they
    // enforce the same thing.
    $snapshot = SchemaBuilder::make()
        ->table('orders', fn ($t) => $t->column('tenant_id', 'bigint unsigned')->column('number', 'int')->primary(['tenant_id', 'number']))
        ->table('lines', fn ($t) => $t->id()->column('tenant_id', 'bigint unsigned')->column('order_number', 'int')
            ->compositeForeign(['tenant_id', 'order_number'], 'orders', ['tenant_id', 'number'], name: 'lines_order_a')
            ->compositeForeign(['order_number', 'tenant_id'], 'orders', ['number', 'tenant_id'], name: 'lines_order_b'))
        ->build();

    expect(doctorCheck(new DuplicateForeignKey, $snapshot))
        ->toHaveFinding('TRUSS-INT-004', table: 'lines', column: 'lines_order_b');
});

it('is clean when the same column references two different tables', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('admins', fn ($t) => $t->id())
        ->table('posts', fn ($t) => $t->id()->foreignId('user_id')->on('users')
            ->foreign('user_id', 'admins', name: 'posts_user_id_admins_foreign'))
        ->build();

    expect(doctorCheck(new DuplicateForeignKey, $snapshot))->toBeClean();
});

it('is clean when a composite key pairs the same columns differently', function () {
    $snapshot = SchemaBuilder::make()
        ->table('pairs', fn ($t) => $t->column('a', 'int')->column('b', 'int')->primary(['a', 'b']))
        ->table('links', fn ($t) => $t->id()->column('x', 'int')->column('y', 'int')
            ->compositeForeign(['x', 'y'], 'pairs', ['a', 'b'], name: 'links_straight')
            ->compositeForeign(['x', 'y'], 'pairs', ['b', 'a'], name: 'links_crossed'))
        ->build();

    expect(doctorCheck(new DuplicateForeignKey, $snapshot))->toBeClean();
});

it('is clean when a single key is a subset of a composite one', function () {
    $snapshot = SchemaBuilder::make()
        ->table('orders', fn ($t) => $t->column('tenant_id', 'bigint unsigned')->column('number', 'int')->primary(['tenant_id', 'number'])->unique('tenant_id'))
        ->table('lines', fn ($t) => $t->id()->column('tenant_id', 'bigint unsigned')->column('order_number', 'int')
            ->compositeForeign(['tenant_id', 'order_number'], 'orders', ['tenant_id', 'number'], name: 'lines_order')
            ->foreign('tenant_id', 'orders', 'tenant_id'))
        ->build();

    expect(doctorCheck(new DuplicateForeignKey, $snapshot))->toBeClean();
});

it('reports every extra copy, not only the second', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('posts', fn ($t) => $t->id()->foreignId('user_id')->on('users')
            ->foreign('user_id', 'users', name: 'copy_2')
            ->foreign('user_id', 'users', name: 'copy_3'))
        ->build();

    expect(doctorCheck(new DuplicateForeignKey, $snapshot))
        ->toHaveCount(2)
        ->toHaveFinding('TRUSS-INT-004', table: 'posts', column: 'copy_2')
        ->toHaveFinding('TRUSS-INT-004', table: 'posts', column: 'copy_3');
});

it('names the columns when the constraints have no name, as on SQLite', function () {
    $snapshot = SchemaBuilder::make(driver: 'sqlite')
        ->table('users', fn ($t) => $t->id())
        ->table('posts', fn ($t) => $t->id()->foreignId('user_id')
            ->foreign('user_id', 'users', name: '')
            ->foreign('user_id', 'users', name: '')
            ->foreign('user_id', 'users', name: ''))
        ->build();

    $findings = doctorCheck(new DuplicateForeignKey, $snapshot);

    // Unnamed copies cannot be told apart, so the key is reported once.
    expect($findings)->toHaveCount(1)
        ->toHaveFinding('TRUSS-INT-004', table: 'posts', column: 'user_id');
    expect($findings[0]->message)->not->toContain('""')->toContain('(user_id)');
});
