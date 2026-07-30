<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Rules\Index\UnindexedSoftDelete;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

it('flags a deleted_at column with no index', function () {
    $snapshot = SchemaBuilder::make()
        ->table('posts', fn ($t) => $t->id()->string('title')->softDeletes())
        ->build();

    expect(doctorCheck(new UnindexedSoftDelete, $snapshot))
        ->toHaveFinding('TRUSS-IDX-005', table: 'posts', column: 'deleted_at');
});

it('is clean when deleted_at is indexed', function () {
    $snapshot = SchemaBuilder::make()
        ->table('posts', fn ($t) => $t->id()->softDeletes()->index('deleted_at'))
        ->build();

    expect(doctorCheck(new UnindexedSoftDelete, $snapshot))->toBeClean();
});

it('accepts deleted_at inside a composite index', function () {
    $snapshot = SchemaBuilder::make()
        ->table('posts', fn ($t) => $t->id()->string('status')->softDeletes()->index(['status', 'deleted_at']))
        ->build();

    expect(doctorCheck(new UnindexedSoftDelete, $snapshot))->toBeClean();
});

it('ignores tables without soft deletes', function () {
    $snapshot = SchemaBuilder::make()->table('posts', fn ($t) => $t->id())->build();

    expect(doctorCheck(new UnindexedSoftDelete, $snapshot))->toBeClean();
});
