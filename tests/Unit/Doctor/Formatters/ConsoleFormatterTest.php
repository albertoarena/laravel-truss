<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\FindingCollection;
use AlbertoArena\Truss\Doctor\Formatters\ConsoleFormatter;
use AlbertoArena\Truss\Doctor\Severity;

it('renders a summary and a line per finding, grouped by table', function () {
    $findings = (new FindingCollection(
        new Finding('TRUSS-INT-001', Severity::Error, 'mysql', 'logs', null, 'Table "logs" has no primary key.', 'hint'),
        new Finding('TRUSS-IDX-006', Severity::Warning, 'mysql', 'users', 'email', 'No unique constraint.', 'hint'),
    ))->sorted();

    $output = (new ConsoleFormatter)->format($findings);

    expect($output)
        ->toContain('2 findings')
        ->toContain('1 error')
        ->toContain('1 warning')
        ->toContain('logs')
        ->toContain('TRUSS-INT-001')
        ->toContain('users.email')
        ->toContain('TRUSS-IDX-006');
});

it('reports a clean schema', function () {
    expect((new ConsoleFormatter)->format(new FindingCollection))->toContain('no findings');
});
