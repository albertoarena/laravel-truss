<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Rules\Index\DuplicateIndex;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

it('flags a second index on the same columns', function () {
    $snapshot = SchemaBuilder::make()
        ->table('posts', fn ($t) => $t->id()->string('slug')
            ->index('slug', 'posts_slug_index')
            ->index('slug', 'posts_slug_index_copy'))
        ->build();

    expect(doctorCheck(new DuplicateIndex, $snapshot))
        ->toHaveFinding('TRUSS-IDX-002', table: 'posts');
});

it('is clean when indexes cover different columns', function () {
    $snapshot = SchemaBuilder::make()
        ->table('posts', fn ($t) => $t->id()->string('slug')->string('title')
            ->index('slug')->index('title'))
        ->build();

    expect(doctorCheck(new DuplicateIndex, $snapshot))->toBeClean();
});

it('treats a different column order as not a duplicate', function () {
    $snapshot = SchemaBuilder::make()
        ->table('events', fn ($t) => $t->id()->string('a')->string('b')
            ->index(['a', 'b'])->index(['b', 'a']))
        ->build();

    expect(doctorCheck(new DuplicateIndex, $snapshot))->toBeClean();
});

it('does not call an index with an expression a duplicate of one on its columns alone', function () {
    $snapshot = SchemaBuilder::make()
        ->table('members', fn ($t) => $t->id()->integer('tenant_id')->string('email')
            ->index('tenant_id', 'members_tenant_id_index')
            ->expressionIndex(['tenant_id', ['expression' => 'lower(email)']], 'members_tenant_email_index'))
        ->build();

    expect(doctorCheck(new DuplicateIndex, $snapshot))->toBeClean();
});

it('flags two indexes with the same expression key', function () {
    $snapshot = SchemaBuilder::make()
        ->table('members', fn ($t) => $t->id()->string('email')
            ->expressionIndex([['expression' => 'lower(email)']], 'members_email_lower_index')
            ->expressionIndex([['expression' => 'lower(email)']], 'members_email_lower_copy'))
        ->build();

    expect(doctorCheck(new DuplicateIndex, $snapshot))
        ->toHaveFinding('TRUSS-IDX-002', table: 'members');
});
