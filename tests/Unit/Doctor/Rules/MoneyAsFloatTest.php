<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Rules\Type\MoneyAsFloat;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

it('flags a money-looking column stored as float', function () {
    $snapshot = SchemaBuilder::make()
        ->table('orders', fn ($t) => $t->id()->column('total', 'double'))
        ->build();

    expect(doctorCheck(new MoneyAsFloat, $snapshot))
        ->toHaveFinding('TRUSS-TYP-001', table: 'orders', column: 'total');
});

it('is clean when the money column is a decimal', function () {
    $snapshot = SchemaBuilder::make()
        ->table('orders', fn ($t) => $t->id()->column('total', 'decimal(10,2)'))
        ->build();

    expect(doctorCheck(new MoneyAsFloat, $snapshot))->toBeClean();
});

it('does not flag a non-money float column', function () {
    // A ratio or weight is legitimately floating point.
    $snapshot = SchemaBuilder::make()
        ->table('measurements', fn ($t) => $t->id()->column('ratio', 'double')->column('weight', 'float'))
        ->build();

    expect(doctorCheck(new MoneyAsFloat, $snapshot))->toBeClean();
});

it('flags at error severity', function () {
    $snapshot = SchemaBuilder::make()
        ->table('invoices', fn ($t) => $t->id()->column('amount', 'float'))
        ->build();

    expect(doctorCheck(new MoneyAsFloat, $snapshot)[0]->severity->value)->toBe('error');
});
