<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Rules\Integrity\ForeignKeyTypeMismatch;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

it('flags a foreign key whose column type differs from the referenced key', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())                  // id is bigint unsigned
        ->table('posts', fn ($t) => $t->id()->integer('user_id')->foreign('user_id', 'users'))
        ->build();

    expect(doctorCheck(new ForeignKeyTypeMismatch, $snapshot))
        ->toHaveFinding('TRUSS-INT-003', table: 'posts', column: 'user_id');
});

it('is clean when the foreign key type matches the referenced key', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id())
        ->table('posts', fn ($t) => $t->id()->foreignId('user_id')->on('users'))
        ->build();

    expect(doctorCheck(new ForeignKeyTypeMismatch, $snapshot))->toBeClean();
});

it('skips when the referenced table is not in the snapshot', function () {
    // The referenced table is excluded or lives in another database, so there is
    // nothing to compare the type against.
    $snapshot = SchemaBuilder::make()
        ->table('posts', fn ($t) => $t->id()->integer('user_id')->foreign('user_id', 'users'))
        ->build();

    expect(doctorCheck(new ForeignKeyTypeMismatch, $snapshot))->toBeClean();
});
