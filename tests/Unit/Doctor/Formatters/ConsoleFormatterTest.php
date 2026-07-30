<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\FindingCollection;
use AlbertoArena\Truss\Doctor\Formatters\ConsoleFormatter;
use AlbertoArena\Truss\Doctor\Severity;

it('renders a summary and a bordered table grouped by table', function () {
    $findings = (new FindingCollection(
        new Finding('TRUSS-INT-001', Severity::Error, 'mysql', 'logs', null, 'Table "logs" has no primary key.', 'hint'),
        new Finding('TRUSS-IDX-006', Severity::Warning, 'mysql', 'users', 'email', 'No unique constraint.', 'hint'),
    ))->sorted();

    $output = (new ConsoleFormatter)->format($findings);

    expect($output)
        ->toContain('2 findings')
        ->toContain('1 error')
        ->toContain('1 warning')
        ->toContain('logs')           // group header
        ->toContain('TRUSS-INT-001')
        ->toContain('users')          // group header
        ->toContain('email')          // location column (column name only)
        ->toContain('TRUSS-IDX-006')
        ->toContain('|')              // bordered table
        ->toContain('+--');           // bordered table
});

it('marks a table-level finding in the location column', function () {
    $findings = new FindingCollection(
        new Finding('TRUSS-INT-001', Severity::Error, 'mysql', 'logs', null, 'Table "logs" has no primary key.', 'hint'),
    );

    expect((new ConsoleFormatter)->format($findings))->toContain('(table)');
});

it('wraps a long message so the table stays narrow', function () {
    $long = 'This is a deliberately very long finding message that must wrap across '
        .'multiple lines instead of stretching the rendered table far beyond a '
        .'comfortable terminal width.';

    $output = (new ConsoleFormatter)->format(new FindingCollection(
        new Finding('TRUSS-INT-001', Severity::Error, 'mysql', 'logs', 'col', $long, 'hint'),
    ));

    // Every word survives the wrapping...
    foreach (['deliberately', 'wrap', 'terminal', 'width'] as $word) {
        expect($output)->toContain($word);
    }

    // ...but the message is broken across lines, not emitted whole.
    expect($output)->not->toContain($long);

    // And no rendered line runs away with the table width.
    $widest = max(array_map('mb_strlen', explode("\n", $output)));
    expect($widest)->toBeLessThanOrEqual(120);
});

it('reports a clean schema', function () {
    expect((new ConsoleFormatter)->format(new FindingCollection))->toContain('no findings');
});
