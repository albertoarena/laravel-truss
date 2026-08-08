<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 0 CI-lane probe. Proves the active database lane (in-memory SQLite
 * locally, MySQL and Postgres on the new CI service lanes) can create and
 * introspect a table, and, where the driver supports native schema comments,
 * that a column comment survives a round-trip through Laravel's introspection.
 *
 * That comment round-trip is exactly what the workstream A `database` annotation
 * source will read, so this proves the lane is meaningful before that code
 * exists. It is also the reason the MySQL/Postgres lanes are a prerequisite:
 * SQLite cannot exercise the comment path at all.
 */

afterEach(function () {
    Schema::dropIfExists('truss_lane_probe');
});

it('creates and introspects a table on the active connection', function () {
    Schema::create('truss_lane_probe', function ($table) {
        $table->id();
        $table->string('status')->comment('0 draft, 1 paid, 2 refunded');
    });

    expect(Schema::hasTable('truss_lane_probe'))->toBeTrue();

    $columns = collect(Schema::getColumns('truss_lane_probe'))->pluck('name');

    expect($columns)->toContain('id')
        ->and($columns)->toContain('status');
});

it('round-trips a native column comment where the driver supports it', function () {
    $driver = DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        // SQLite has no column comments; this path is covered on the CI
        // MySQL/Postgres lanes, which is the point of adding them.
        $this->markTestSkipped('SQLite has no native column comments; covered on the MySQL/Postgres lanes.');
    }

    Schema::create('truss_lane_probe', function ($table) {
        $table->id();
        $table->string('status')->comment('0 draft, 1 paid, 2 refunded');
    });

    $status = collect(Schema::getColumns('truss_lane_probe'))->firstWhere('name', 'status');

    expect($status['comment'])->toBe('0 draft, 1 paid, 2 refunded');
});
