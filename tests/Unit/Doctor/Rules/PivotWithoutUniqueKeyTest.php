<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Rules\Integrity\PivotWithoutUniqueKey;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

it('flags a two-foreign-key table with no unique constraint on the pair', function () {
    // An id primary key does not stop duplicate (team_id, user_id) rows.
    $snapshot = SchemaBuilder::make()
        ->table('teams', fn ($t) => $t->id())
        ->table('users', fn ($t) => $t->id())
        ->table('team_user', fn ($t) => $t->id()
            ->foreignId('team_id')->on('teams')
            ->foreignId('user_id')->on('users'))
        ->build();

    expect(doctorCheck(new PivotWithoutUniqueKey, $snapshot))
        ->toHaveFinding('TRUSS-INT-007', table: 'team_user');
});

it('is clean when the key pair is the composite primary key', function () {
    $snapshot = SchemaBuilder::make()
        ->table('teams', fn ($t) => $t->id())
        ->table('users', fn ($t) => $t->id())
        ->table('team_user', fn ($t) => $t
            ->foreignId('team_id')->on('teams')
            ->foreignId('user_id')->on('users')
            ->primary(['team_id', 'user_id']))
        ->build();

    expect(doctorCheck(new PivotWithoutUniqueKey, $snapshot))->toBeClean();
});

it('is clean when a unique index covers the key pair', function () {
    $snapshot = SchemaBuilder::make()
        ->table('teams', fn ($t) => $t->id())
        ->table('users', fn ($t) => $t->id())
        ->table('team_user', fn ($t) => $t->id()
            ->foreignId('team_id')->on('teams')
            ->foreignId('user_id')->on('users')
            ->unique(['team_id', 'user_id']))
        ->build();

    expect(doctorCheck(new PivotWithoutUniqueKey, $snapshot))->toBeClean();
});

it('ignores tables that are not two-foreign-key pivots', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('posts', fn ($t) => $t->id()->foreignId('user_id')->on('users'))
        ->build();

    expect(doctorCheck(new PivotWithoutUniqueKey, $snapshot))->toBeClean();
});
