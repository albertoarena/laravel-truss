<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Rules\Index\ForeignKeyWithoutIndex;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

it('flags an unindexed foreign key as an error on postgresql', function () {
    $snapshot = SchemaBuilder::make(driver: 'pgsql')
        ->table('users', fn ($t) => $t->id())
        ->table('posts', fn ($t) => $t->id()->foreignId('user_id')->on('users'))
        ->build();

    $findings = doctorCheck(new ForeignKeyWithoutIndex, $snapshot);

    expect($findings)->toHaveFinding('TRUSS-IDX-001', table: 'posts', column: 'user_id')
        ->and($findings[0]->severity->value)->toBe('error');
});

it('is only info on mysql, which indexes foreign keys automatically', function () {
    $snapshot = SchemaBuilder::make(driver: 'mysql')
        ->table('users', fn ($t) => $t->id())
        ->table('posts', fn ($t) => $t->id()->foreignId('user_id')->on('users'))
        ->build();

    expect(doctorCheck(new ForeignKeyWithoutIndex, $snapshot)[0]->severity->value)->toBe('info');
});

it('is clean when the foreign key column is indexed', function () {
    $snapshot = SchemaBuilder::make(driver: 'pgsql')
        ->table('users', fn ($t) => $t->id())
        ->table('posts', fn ($t) => $t->id()->foreignId('user_id')->on('users')->index('user_id'))
        ->build();

    expect(doctorCheck(new ForeignKeyWithoutIndex, $snapshot))->toBeClean();
});

it('accepts a composite index that leads with the foreign key column', function () {
    $snapshot = SchemaBuilder::make(driver: 'pgsql')
        ->table('users', fn ($t) => $t->id())
        ->table('posts', fn ($t) => $t->id()
            ->foreignId('user_id')->on('users')
            ->column('created_at', 'timestamp', true)
            ->index(['user_id', 'created_at']))
        ->build();

    expect(doctorCheck(new ForeignKeyWithoutIndex, $snapshot))->toBeClean();
});
