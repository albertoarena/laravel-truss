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
