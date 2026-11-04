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

it('runs on the driver DB_CONNECTION names', function () {
    // An unknown DB_CONNECTION falls back to SQLite, so a lane that names a
    // driver TestCase does not map would pass while testing nothing new.
    expect(DB::connection()->getDriverName())->toBe(env('DB_CONNECTION', 'sqlite'));
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

/*
 * A torn-down test's application holds its database connection in reference
 * cycles, so without an explicit disconnect the PDO stays open until PHP's
 * cycle collector happens to run. On the Postgres lane that let dead tests pile
 * up past max_connections ("too many clients already") once Pest 4 changed when
 * the collector runs. The pair below runs in file order: the first keeps a
 * reference to its connection, the second checks it was closed when the first
 * test ended, rather than left to garbage collection.
 */
it('opens a connection that the next test can inspect', function () {
    DB::connection()->getPdo();

    $GLOBALS['truss_lane_previous_connection'] = DB::connection();

    expect(DB::connection()->getRawPdo())->not->toBeNull();
});

it('closes the previous test\'s connection when that test ends', function () {
    $previous = $GLOBALS['truss_lane_previous_connection'] ?? null;
    unset($GLOBALS['truss_lane_previous_connection']);

    expect($previous)->not->toBeNull()
        ->and($previous->getRawPdo())->toBeNull();
});
