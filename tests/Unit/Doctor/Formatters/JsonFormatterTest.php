<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\FindingCollection;
use AlbertoArena\Truss\Doctor\Formatters\JsonFormatter;
use AlbertoArena\Truss\Doctor\Severity;

it('renders findings and a summary as json', function () {
    $findings = (new FindingCollection(
        new Finding('TRUSS-INT-001', Severity::Error, 'mysql', 'logs', null, 'no pk', 'hint'),
        new Finding('TRUSS-IDX-006', Severity::Warning, 'mysql', 'users', 'email', 'no unique', 'hint', 'alter table ...'),
    ))->sorted();

    $data = json_decode((new JsonFormatter)->format($findings), true, flags: JSON_THROW_ON_ERROR);

    expect($data['summary'])->toBe(['total' => 2, 'error' => 1, 'warning' => 1, 'info' => 0]);
    expect($data['findings'][0]['code'])->toBe('TRUSS-INT-001');
    expect($data['findings'][0])->toHaveKeys(['code', 'severity', 'connection', 'table', 'column', 'message', 'hint', 'suggestion', 'fingerprint']);
    expect($data['findings'][1]['column'])->toBe('email');
    expect($data['findings'][1]['suggestion'])->toBe('alter table ...');
});

it('renders an empty finding list for a clean schema', function () {
    $data = json_decode((new JsonFormatter)->format(new FindingCollection), true, flags: JSON_THROW_ON_ERROR);

    expect($data['findings'])->toBe([]);
    expect($data['summary']['total'])->toBe(0);
});
