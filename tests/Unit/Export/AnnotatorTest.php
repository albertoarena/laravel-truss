<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\Annotator;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

function annotatableTables(): array
{
    return SchemaBuilder::make()
        ->table('orders', function ($t) {
            $t->id();
            $t->integer('status');
        })
        ->table('users', fn ($t) => $t->id())
        ->build()['tables'];
}

it('attaches config table and column annotations', function () {
    $annotator = new Annotator(
        tableNotes: ['orders' => 'One row per order.'],
        columnNotes: ['orders.status' => '0 draft, 1 paid, 2 refunded'],
    );

    $tables = $annotator->annotate(annotatableTables());
    $orders = collect($tables)->firstWhere('name', 'orders');
    $status = collect($orders['columns'])->firstWhere('name', 'status');

    expect($orders['annotation'])->toBe('One row per order.')
        ->and($status['annotation'])->toBe('0 draft, 1 paid, 2 refunded');
});

it('leaves tables with no annotation untouched', function () {
    // The byte-identity guarantee: no annotation, no extra keys, so an
    // un-annotated export is unchanged.
    $tables = (new Annotator)->annotate(annotatableTables());
    $users = collect($tables)->firstWhere('name', 'users');

    expect($users)->not->toHaveKey('annotation')
        ->and($users['columns'][0])->not->toHaveKey('annotation');
});

it('resolves precedence first-match-wins in source order', function () {
    $config = new Annotator(
        source: ['config', 'database'],
        columnNotes: ['orders.status' => 'from config'],
        comments: ['orders.status' => 'from database'],
    );
    $database = new Annotator(
        source: ['database', 'config'],
        columnNotes: ['orders.status' => 'from config'],
        comments: ['orders.status' => 'from database'],
    );

    expect($config->for('orders.status'))->toBe('from config')
        ->and($database->for('orders.status'))->toBe('from database');
});

it('falls through to the next source when the first is empty', function () {
    $annotator = new Annotator(
        source: ['config', 'database'],
        comments: ['orders' => 'from database'],
    );

    expect($annotator->for('orders'))->toBe('from database');
});

it('ignores an annotation for a table or column that does not exist', function () {
    $annotator = new Annotator(
        tableNotes: ['ghost' => 'gone', 'orders' => 'here'],
        columnNotes: ['orders.missing' => 'gone too'],
    );

    $tables = $annotator->annotate(annotatableTables());
    $names = array_column($tables, 'name');

    expect($names)->toBe(['orders', 'users'])
        ->and(collect($tables)->firstWhere('name', 'orders')['annotation'])->toBe('here');
});

it('exposes the trimmed, non-empty global notes', function () {
    $annotator = new Annotator(notes: ['All timestamps are UTC.', '  ', 'Money is cents.']);

    expect($annotator->notes())->toBe(['All timestamps are UTC.', 'Money is cents.']);
});

it('builds from the config array shape', function () {
    $annotator = Annotator::fromConfig([
        'source' => ['config'],
        'notes' => ['UTC.'],
        'tables' => ['orders' => 'One per order.'],
        'columns' => ['orders.status' => 'enum'],
    ]);

    expect($annotator->for('orders'))->toBe('One per order.')
        ->and($annotator->for('orders.status'))->toBe('enum')
        ->and($annotator->notes())->toBe(['UTC.']);
});
