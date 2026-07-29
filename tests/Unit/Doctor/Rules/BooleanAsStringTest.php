<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Rules\Type\BooleanAsString;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

it('flags an is_ column stored as varchar', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id()->string('is_active'))
        ->build();

    expect(doctorCheck(new BooleanAsString, $snapshot))
        ->toHaveFinding('TRUSS-TYP-002', table: 'users', column: 'is_active');
});

it('flags a has_ column too', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id()->string('has_access'))
        ->build();

    expect(doctorCheck(new BooleanAsString, $snapshot))
        ->toHaveFinding('TRUSS-TYP-002', table: 'users', column: 'has_access');
});

it('is clean when the flag is a tinyint', function () {
    $snapshot = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id()->column('is_active', 'tinyint(1)'))
        ->build();

    expect(doctorCheck(new BooleanAsString, $snapshot))->toBeClean();
});

it('does not flag a normal column that merely contains is', function () {
    $snapshot = SchemaBuilder::make()
        ->table('posts', fn ($t) => $t->id()->string('title'))
        ->build();

    expect(doctorCheck(new BooleanAsString, $snapshot))->toBeClean();
});
