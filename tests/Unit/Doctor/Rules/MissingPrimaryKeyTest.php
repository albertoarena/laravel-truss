<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Rules\Integrity\MissingPrimaryKey;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

it('flags a table with no primary key', function () {
    $snapshot = SchemaBuilder::make()
        ->table('logs', fn ($t) => $t->string('message'))
        ->build();

    expect(doctorCheck(new MissingPrimaryKey, $snapshot))
        ->toHaveFinding('TRUSS-INT-001', table: 'logs');
});

it('is clean when the table has a primary key', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id()->string('email'))
        ->build();

    expect(doctorCheck(new MissingPrimaryKey, $snapshot))->toBeClean();
});

it('accepts a composite primary key (pivot table)', function () {
    $snapshot = SchemaBuilder::make()
        ->table('role_user', fn ($t) => $t->integer('role_id')->integer('user_id')->primary(['role_id', 'user_id']))
        ->build();

    expect(doctorCheck(new MissingPrimaryKey, $snapshot))->toBeClean();
});

it('reports the connection on the finding', function () {
    $snapshot = SchemaBuilder::make('tenant')
        ->table('logs', fn ($t) => $t->string('message'))
        ->build();

    $findings = doctorCheck(new MissingPrimaryKey, $snapshot, 'tenant');

    expect($findings[0]->connection)->toBe('tenant')
        ->and($findings[0]->severity->value)->toBe('error');
});
