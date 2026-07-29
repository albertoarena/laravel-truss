<?php

declare(strict_types=1);

use AlbertoArena\Truss\Introspection\SnapshotBuilder;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

beforeEach(function () {
    (require __DIR__.'/../../Fixtures/migrations/0001_01_01_000000_create_fixture_tables.php')->up();
});

// The fixture SchemaBuilder must expose the same array shape real introspection
// produces, or rules unit-tested against it would drift from the production
// snapshot. This pins the table and column shape; index/foreign-key shape is
// added here as SchemaBuilder grows methods for them.
it('produces the same table and column shape as real introspection', function () {
    $real = collect((new SnapshotBuilder)->build('testing')['tables'])->firstWhere('name', 'users');

    $built = SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id()->string('email'))
        ->build()['tables'][0];

    expect(array_keys($built))->toBe(array_keys($real))
        ->and(array_keys($built['columns'][0]))->toBe(array_keys($real['columns'][0]));
});
