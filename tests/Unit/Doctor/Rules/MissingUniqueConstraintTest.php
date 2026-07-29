<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Rules\Index\MissingUniqueConstraint;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

it('flags an email column with no unique constraint', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id()->string('email'))
        ->build();

    expect(doctorCheck(new MissingUniqueConstraint, $snapshot))
        ->toHaveFinding('TRUSS-IDX-006', table: 'users', column: 'email');
});

it('is clean when the column has a unique index', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id()->string('email')->unique('email'))
        ->build();

    expect(doctorCheck(new MissingUniqueConstraint, $snapshot))->toBeClean();
});

it('accepts a scoped composite unique', function () {
    // email unique per team, a legitimate design, not a global unique.
    $snapshot = SchemaBuilder::make()
        ->table('members', fn ($t) => $t->id()->integer('team_id')->string('email')->unique(['team_id', 'email']))
        ->build();

    expect(doctorCheck(new MissingUniqueConstraint, $snapshot))->toBeClean();
});

it('flags slug, uuid and token too', function () {
    $snapshot = SchemaBuilder::make()
        ->table('posts', fn ($t) => $t->id()->string('slug')->string('uuid')->string('token'))
        ->build();

    expect(doctorCheck(new MissingUniqueConstraint, $snapshot))
        ->toHaveFinding('TRUSS-IDX-006', table: 'posts', column: 'slug')
        ->toHaveFinding('TRUSS-IDX-006', table: 'posts', column: 'uuid')
        ->toHaveFinding('TRUSS-IDX-006', table: 'posts', column: 'token');
});

it('is heuristic', function () {
    expect((new MissingUniqueConstraint)->confidence()->value)->toBe('heuristic');
});
