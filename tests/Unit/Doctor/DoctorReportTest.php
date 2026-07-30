<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\DoctorReport;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

it('runs the configured rules over a snapshot and returns findings', function () {
    $snapshot = SchemaBuilder::make('testing')
        ->table('ledger', fn ($t) => $t->string('note')) // no primary key -> INT-001
        ->build();

    expect((new DoctorReport)->for('testing', $snapshot))
        ->toHaveFinding('TRUSS-INT-001', table: 'ledger');
});

it('runs the recommended preset by default and adds heuristics under strict', function () {
    $snapshot = SchemaBuilder::make('testing')
        ->table('flags', fn ($t) => $t->id()->string('is_active')) // is_* varchar -> TYP-002 (heuristic)
        ->build();

    // recommended (default) keeps heuristic rules quiet.
    expect((new DoctorReport)->for('testing', $snapshot))
        ->not->toHaveFinding('TRUSS-TYP-002', table: 'flags');

    // strict switches them on.
    expect((new DoctorReport)->for('testing', $snapshot, preset: 'strict'))
        ->toHaveFinding('TRUSS-TYP-002', table: 'flags');
});

it('drops excluded tables before running rules', function () {
    config()->set('truss.doctor.exclude', ['ledger']);

    $snapshot = SchemaBuilder::make('testing')
        ->table('ledger', fn ($t) => $t->string('note')) // would be INT-001
        ->build();

    expect((new DoctorReport)->for('testing', $snapshot))->toBeClean();
});

it('serializes a report to a payload with a summary and per-finding confidence and category', function () {
    $snapshot = SchemaBuilder::make('testing')
        ->table('ledger', fn ($t) => $t->string('note'))
        ->build();

    $report = new DoctorReport;
    $payload = $report->toArray($report->for('testing', $snapshot));

    expect($payload['summary'])->toMatchArray(['total' => 1, 'error' => 1, 'warning' => 0, 'info' => 0]);

    expect($payload['findings'][0])
        ->toHaveKeys(['code', 'severity', 'table', 'column', 'message', 'hint', 'confidence', 'category'])
        ->and($payload['findings'][0]['code'])->toBe('TRUSS-INT-001')
        ->and($payload['findings'][0]['severity'])->toBe('error')
        ->and($payload['findings'][0]['confidence'])->toBe('high')
        ->and($payload['findings'][0]['category'])->toBe('integrity');
});

it('marks a heuristic finding with heuristic confidence in the payload', function () {
    $snapshot = SchemaBuilder::make('testing')
        ->table('flags', fn ($t) => $t->id()->string('is_active'))
        ->build();

    $report = new DoctorReport;
    $payload = $report->toArray($report->for('testing', $snapshot, preset: 'strict'));

    $typ = collect($payload['findings'])->firstWhere('code', 'TRUSS-TYP-002');

    expect($typ['confidence'])->toBe('heuristic');
});
