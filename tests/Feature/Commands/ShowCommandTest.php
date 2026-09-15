<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('widgets', function ($table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('parts', function ($table) {
        $table->id();
        $table->foreignId('widget_id')->constrained();
    });
});

it('prints the schema as a table of tables', function () {
    $this->artisan('truss:show')
        ->assertSuccessful()
        ->expectsOutputToContain('widgets')
        ->expectsOutputToContain('parts')
        ->expectsOutputToContain('Foreign keys');
});

it('points the reader at the visual dashboard command', function () {
    $this->artisan('truss:show')
        ->assertSuccessful()
        ->expectsOutputToContain('truss:open');
});

it('shows a specific connection with --connection', function () {
    $this->artisan('truss:show', ['--connection' => 'testing'])
        ->assertSuccessful()
        ->expectsOutputToContain('widgets');
});

it('drops excluded tables, as every other surface does', function () {
    config()->set('truss.excluded_tables', ['parts']);

    // The command reads the cached snapshot, which is deliberately unfiltered so
    // that changing the exclusion list needs no rebuild. Filtering is therefore
    // the caller's job here exactly as it is in the dashboard payload, and for a
    // long time this one caller did not do it while its docblock said it did.
    $this->artisan('truss:show')
        ->assertSuccessful()
        ->expectsOutputToContain('widgets')
        ->doesntExpectOutputToContain('parts');
});

it('says how much of the schema it is showing when tables were excluded', function () {
    config()->set('truss.excluded_tables', ['parts']);

    $this->artisan('truss:show')
        ->assertSuccessful()
        ->expectsOutputToContain('1 of 2 tables on testing');
});

it('states a plain count when nothing was excluded', function () {
    config()->set('truss.excluded_tables', []);

    $this->artisan('truss:show')
        ->assertSuccessful()
        ->expectsOutputToContain('2 tables on testing')
        ->doesntExpectOutputToContain('of 2 tables');
});

it('honours per-connection exclusions too', function () {
    config()->set('truss.excluded_tables', []);
    config()->set('truss.connections', ['testing' => ['excluded_tables' => ['parts']]]);

    $this->artisan('truss:show', ['--connection' => 'testing'])
        ->assertSuccessful()
        ->doesntExpectOutputToContain('parts');
});

it('reports an all-excluded connection as empty rather than pretending', function () {
    config()->set('truss.excluded_tables', ['widgets', 'parts']);

    $this->artisan('truss:show')
        ->assertSuccessful()
        ->expectsOutputToContain('excluded by config');
});
