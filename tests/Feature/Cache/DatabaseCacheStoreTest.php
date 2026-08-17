<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * The scenario from #51, reproduced literally: `CACHE_STORE=database` (Laravel's
 * own stock default) pointed at a database that has no `cache` table yet, which
 * is what a partial migration batch, a `migrate:rollback` past the cache table,
 * or a secondary connection leaves behind.
 *
 * The rest of the suite forces the array store, so without this lane nothing
 * exercises a cache store that can fail at all.
 */
beforeEach(function () {
    config()->set('truss.enabled', true);
    config()->set('cache.default', 'database');
    Gate::define('viewTruss', fn ($user = null) => true);

    expect(Schema::hasTable('cache'))->toBeFalse();
});

it('does not fail the migration when the cache table does not exist', function () {
    event(new MigrationsEnded('up', ['database' => 'testing']));

    // Reaching this line is half the point: the listener must not throw. The
    // other half is that the snapshot genuinely was not cached, which is what
    // the degraded has() reports.
    expect(app(SchemaCacheRepository::class)->has('testing'))->toBeFalse();
});

it('does not fail a rollback past the cache table', function () {
    // migrate:rollback on a stock Laravel app rolls back the batch that created
    // the cache table, then fires this event against a store that is gone.
    event(new MigrationsEnded('down', ['database' => 'testing']));

    expect(app(SchemaCacheRepository::class)->has('testing'))->toBeFalse();
});

it('reports the failed read rather than the follow-up failed write', function () {
    // Both fail on an unprovisioned store. The read is the diagnostic one, and
    // the write echoes the entire serialized snapshot back inside its SQL.
    $repo = new SchemaCacheRepository;
    $repo->get('testing');

    expect($repo->lastError())->toContain('select')
        ->and($repo->lastError())->not->toContain('insert');
});

it('serves the schema endpoint instead of returning 500', function () {
    Schema::create('posts', function ($table) {
        $table->id();
    });

    $this->getJson('/truss/api/schema')
        ->assertOk()
        ->assertJsonPath('cache_unavailable', true)
        ->assertJsonPath('tables.0.name', 'posts');
});
