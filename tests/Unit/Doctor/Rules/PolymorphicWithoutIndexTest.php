<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Rules\Integrity\PolymorphicWithoutIndex;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

it('flags a *_type/*_id pair with no composite index', function () {
    $snapshot = SchemaBuilder::make()
        ->table('comments', fn ($t) => $t->id()
            ->string('commentable_type')
            ->column('commentable_id', 'bigint unsigned'))
        ->build();

    expect(doctorCheck(new PolymorphicWithoutIndex, $snapshot))
        ->toHaveFinding('TRUSS-INT-009', table: 'comments', column: 'commentable_type');
});

it('is clean when the morph pair has its composite index', function () {
    $snapshot = SchemaBuilder::make()
        ->table('comments', fn ($t) => $t->id()->morphs('commentable'))
        ->build();

    expect(doctorCheck(new PolymorphicWithoutIndex, $snapshot))->toBeClean();
});

it('does not treat a lone *_type column as a morph pair', function () {
    // A "type" column with no matching *_id is not a polymorphic relation.
    $snapshot = SchemaBuilder::make()
        ->table('products', fn ($t) => $t->id()->string('product_type'))
        ->build();

    expect(doctorCheck(new PolymorphicWithoutIndex, $snapshot))->toBeClean();
});

it('is heuristic', function () {
    expect((new PolymorphicWithoutIndex)->confidence()->value)->toBe('heuristic');
});
