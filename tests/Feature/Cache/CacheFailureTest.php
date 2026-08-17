<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Tests\Support\BrokenCacheStore;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * An unusable cache store must never crash anything: not the host app's
 * `php artisan migrate`, not the dashboard, not the commands. See #51 and
 * docs/plans/cache-store-resilience.md.
 */
beforeEach(function () {
    config()->set('truss.enabled', true);
    Gate::define('viewTruss', fn ($user = null) => true);

    Cache::extend('broken', fn () => Cache::repository(new BrokenCacheStore));
    config()->set('cache.stores.broken', ['driver' => 'broken']);
    config()->set('cache.default', 'broken');
});

it('does not let a broken cache store fail the migration', function () {
    Schema::create('posts', function ($table) {
        $table->id();
    });

    // The listener runs after the migration has already committed, so anything
    // it throws turns a successful migrate into a failed Artisan command.
    event(new MigrationsEnded('up', ['database' => 'testing']));
})->throwsNoExceptions();

it('logs a warning when the migration rebuild could not be cached', function () {
    Log::spy();

    event(new MigrationsEnded('up', ['database' => 'testing']));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'testing'));
});

it('serves the schema live and flags it when the cache store is unavailable', function () {
    Schema::create('posts', function ($table) {
        $table->id();
        $table->string('title');
    });

    $this->getJson('/truss/api/schema')
        ->assertOk()
        ->assertJsonPath('connection', 'testing')
        ->assertJsonPath('cache_unavailable', true)
        ->assertJsonPath('tables.0.name', 'posts');
});

it('omits the cache_unavailable flag when the store works', function () {
    config()->set('cache.default', 'array');

    $this->getJson('/truss/api/schema')
        ->assertOk()
        ->assertJsonMissingPath('cache_unavailable');
});

it('fails truss:rebuild loudly when the snapshot cannot be cached', function () {
    // The documented escape hatch has one job, so it must not report success.
    $this->artisan('truss:rebuild')
        ->assertFailed()
        ->expectsOutputToContain(BrokenCacheStore::MESSAGE);
});

it('still prints the structure from truss:show, with a warning', function () {
    Schema::create('posts', function ($table) {
        $table->id();
    });

    $this->artisan('truss:show')
        ->assertSuccessful()
        ->expectsOutputToContain('cache');
});

it('still reports from truss:doctor when the cache store is unavailable', function () {
    Schema::create('posts', function ($table) {
        $table->id();
    });

    $this->artisan('truss:doctor')->assertSuccessful();
});

it('still exports, warning without corrupting the artifact', function () {
    Schema::create('posts', function ($table) {
        $table->id();
    });

    // Artisan::call, not $this->artisan(): the export notice is written to the
    // error channel, and $this->artisan() hands the command a mocked output whose
    // methods are all public, which hides a visibility error on the real one.
    // This is exactly how the first attempt at that notice shipped a crash.
    expect(Artisan::call('truss:export', ['--format' => 'json']))->toBe(0)
        ->and(Artisan::output())->toContain('cache store is unavailable');
});

it('keeps the recorded reason short enough to print', function () {
    // A failed write echoes the query back, and the query embeds the whole
    // serialized snapshot, so the raw message can be tens of kilobytes of
    // schema. It reaches a terminal and the log, so it gets trimmed.
    config()->set('cache.default', 'database');

    $repo = new SchemaCacheRepository;
    $repo->rebuild('testing');

    expect(strlen((string) $repo->lastError()))->toBeLessThanOrEqual(200);
});

it('degrades every repository read and records the reason', function () {
    $repo = new SchemaCacheRepository;

    expect($repo->peek('testing'))->toBeNull()
        ->and($repo->lastError())->toContain(BrokenCacheStore::MESSAGE)
        ->and($repo->has('testing'))->toBeFalse()
        ->and($repo->forget('testing'))->toBeFalse();
});

it('still returns a usable snapshot from get and rebuild', function () {
    Schema::create('posts', function ($table) {
        $table->id();
    });

    $repo = new SchemaCacheRepository;

    expect($repo->get('testing')['tables'])->toHaveCount(1)
        ->and($repo->rebuild('testing')['tables'])->toHaveCount(1)
        ->and($repo->lastError())->toContain(BrokenCacheStore::MESSAGE);
});

it('logs the read-path failure at debug level, not warning', function () {
    // A dashboard request while Redis is down must not flood the log with
    // warnings: the payload flag and the banner are already telling a human.
    Log::spy();

    (new SchemaCacheRepository)->get('testing');

    Log::shouldHaveReceived('debug');
    Log::shouldNotHaveReceived('warning');
});
