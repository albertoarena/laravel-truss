<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\FindingCollection;
use AlbertoArena\Truss\Doctor\Severity;

function fcFinding(Severity $severity, string $table, string $code): Finding
{
    return new Finding($code, $severity, 'mysql', $table, null, 'a message', 'a hint');
}

it('is empty when it has no findings', function () {
    expect((new FindingCollection)->isEmpty())->toBeTrue();
    expect(new FindingCollection(fcFinding(Severity::Info, 'a', 'TRUSS-X')))->not->toBeEmpty();
});

it('is countable', function () {
    expect(new FindingCollection(
        fcFinding(Severity::Info, 'a', 'TRUSS-X'),
        fcFinding(Severity::Error, 'b', 'TRUSS-Y'),
    ))->toHaveCount(2);
});

it('sorts by severity, then table, then code', function () {
    $collection = new FindingCollection(
        fcFinding(Severity::Info, 'zebra', 'TRUSS-A'),
        fcFinding(Severity::Error, 'posts', 'TRUSS-IDX-002'),
        fcFinding(Severity::Error, 'posts', 'TRUSS-IDX-001'),
        fcFinding(Severity::Warning, 'apples', 'TRUSS-B'),
    );

    $order = array_map(
        fn (Finding $f): string => "{$f->severity->value}:{$f->table}:{$f->code}",
        $collection->sorted()->all(),
    );

    expect($order)->toBe([
        'error:posts:TRUSS-IDX-001',
        'error:posts:TRUSS-IDX-002',
        'warning:apples:TRUSS-B',
        'info:zebra:TRUSS-A',
    ]);
});

it('is iterable', function () {
    $collection = new FindingCollection(fcFinding(Severity::Error, 'b', 'TRUSS-Y'));
    $tables = [];
    foreach ($collection as $finding) {
        $tables[] = $finding->table;
    }
    expect($tables)->toBe(['b']);
});
