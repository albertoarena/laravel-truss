<?php

declare(strict_types=1);

use AlbertoArena\Truss\Introspection\SnapshotBuilder;

/*
 * Points the default connection at an unreachable database and registers the
 * fixture migrations, so build() must fall back to replaying them on in-memory
 * SQLite.
 */
beforeEach(function () {
    config()->set('database.default', 'unreachable');
    config()->set('database.connections.unreachable', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 1, // nothing listens here → connection fails fast
        'database' => 'nope',
        'username' => 'nope',
        'password' => 'nope',
    ]);

    app('migrator')->path(__DIR__.'/../../Fixtures/migrations');
});

it('falls back to SQLite replay when the connection is unreachable', function () {
    $snapshot = (new SnapshotBuilder)->build();

    expect($snapshot['fallback'])->toBeTrue()
        ->and($snapshot['connection'])->toBe('unreachable')
        ->and(collect($snapshot['tables'])->pluck('name'))
        ->toContain('users', 'posts', 'post_tag', 'regions', 'region_stats', 'logs');
});

it('carries the composite-first structure through the fallback', function () {
    $tables = collect((new SnapshotBuilder)->build()['tables'])->keyBy('name');

    expect($tables['post_tag']['primary_key'])->toBe(['post_id', 'tag_id'])
        ->and($tables['logs']['primary_key'])->toBe([]);
});

it('reports skipped migrations rather than aborting on a bad one', function () {
    app('migrator')->path(__DIR__.'/../../Fixtures/bad-migrations');

    $snapshot = (new SnapshotBuilder)->build();

    // The good fixtures still produce a snapshot; the incompatible migration is
    // recorded, not fatal.
    expect($snapshot['fallback'])->toBeTrue()
        ->and(collect($snapshot['tables'])->pluck('name'))->toContain('users')
        ->and($snapshot['skipped_migrations'])
        ->toContain('2999_01_01_000000_incompatible_migration');
});
