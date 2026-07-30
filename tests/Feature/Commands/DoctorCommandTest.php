<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // A clean table and one with a real, high-confidence problem: no primary key.
    Schema::create('members', function ($table) {
        $table->id();
        $table->string('email')->unique();
    });
    Schema::create('ledger', function ($table) {
        $table->string('entry');
    });
});

it('reports the finding and exits 1 when a problem is at the fail level', function () {
    $this->artisan('truss:doctor')
        ->expectsOutputToContain('TRUSS-INT-001')
        ->expectsOutputToContain('ledger')
        ->assertExitCode(1);
});

it('exits 0 and reports a clean run with the none preset', function () {
    $this->artisan('truss:doctor', ['--preset' => 'none'])
        ->expectsOutputToContain('no findings')
        ->assertExitCode(0);
});

it('never fails when --fail-on is never', function () {
    $this->artisan('truss:doctor', ['--fail-on' => 'never'])->assertExitCode(0);
});

it('outputs json with --format=json', function () {
    $this->artisan('truss:doctor', ['--format' => 'json', '--preset' => 'none'])
        ->expectsOutputToContain('"findings"')
        ->expectsOutputToContain('"summary"')
        ->assertExitCode(0);
});

it('rejects an unknown format with exit code 2', function () {
    $this->artisan('truss:doctor', ['--format' => 'nope'])->assertExitCode(2);
});

it('skips a category with --skip', function () {
    $this->artisan('truss:doctor', ['--skip' => 'integrity', '--fail-on' => 'never'])
        ->doesntExpectOutputToContain('TRUSS-INT-001')
        ->assertExitCode(0);
});

it('is also available as truss:check', function () {
    $this->artisan('truss:check', ['--preset' => 'none'])->assertExitCode(0);
});
