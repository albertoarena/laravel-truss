<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Rules\Index\RedundantPrefixIndex;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

it('flags a non-unique index that is a left prefix of another', function () {
    $snapshot = SchemaBuilder::make()
        ->table('events', fn ($t) => $t->id()->string('a')->string('b')
            ->index('a', 'events_a_index')
            ->index(['a', 'b'], 'events_a_b_index'))
        ->build();

    expect(doctorCheck(new RedundantPrefixIndex, $snapshot))
        ->toHaveFinding('TRUSS-IDX-003', table: 'events');
});

it('keeps a unique prefix index, which enforces a constraint the composite does not', function () {
    $snapshot = SchemaBuilder::make()
        ->table('events', fn ($t) => $t->id()->string('a')->string('b')
            ->unique('a', 'events_a_unique')
            ->index(['a', 'b'], 'events_a_b_index'))
        ->build();

    expect(doctorCheck(new RedundantPrefixIndex, $snapshot))->toBeClean();
});

it('is clean when no index is a prefix of another', function () {
    $snapshot = SchemaBuilder::make()
        ->table('events', fn ($t) => $t->id()->string('a')->string('b')
            ->index(['a', 'b'])->index('b'))
        ->build();

    expect(doctorCheck(new RedundantPrefixIndex, $snapshot))->toBeClean();
});
