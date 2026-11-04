<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Rules\Index\IndexDuplicatingPrimaryKey;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

it('flags an index that duplicates the primary key', function () {
    $snapshot = SchemaBuilder::make()
        ->table('team_user', fn ($t) => $t
            ->integer('team_id')->integer('user_id')
            ->primary(['team_id', 'user_id'])
            ->index(['team_id', 'user_id'], 'team_user_redundant'))
        ->build();

    expect(doctorCheck(new IndexDuplicatingPrimaryKey, $snapshot))
        ->toHaveFinding('TRUSS-IDX-004', table: 'team_user');
});

it('is clean when no index matches the primary key', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id()->string('email')->unique('email'))
        ->build();

    expect(doctorCheck(new IndexDuplicatingPrimaryKey, $snapshot))->toBeClean();
});

it('does not call an index on the key column plus an expression a duplicate of the key', function () {
    $snapshot = SchemaBuilder::make()
        ->table('accounts', fn ($t) => $t->integer('code')->string('name')
            ->primary(['code'])
            ->expressionIndex(['code', ['expression' => 'lower(name)']], 'accounts_code_name_index'))
        ->build();

    expect(doctorCheck(new IndexDuplicatingPrimaryKey, $snapshot))->toBeClean();
});
