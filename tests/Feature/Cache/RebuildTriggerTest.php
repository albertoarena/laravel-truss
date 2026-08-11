<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Diff\BaselineStore;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Storage;

it('rebuilds the snapshot when migrations end and Truss is enabled', function () {
    config()->set('truss.enabled', true);
    $repo = app(SchemaCacheRepository::class);

    expect($repo->has('testing'))->toBeFalse();

    event(new MigrationsEnded('up', ['database' => 'testing']));

    expect($repo->has('testing'))->toBeTrue();
});

it('does not auto-rebuild when Truss is disabled', function () {
    config()->set('truss.enabled', false);

    event(new MigrationsEnded('up', ['database' => 'testing']));

    expect(app(SchemaCacheRepository::class)->has('testing'))->toBeFalse();
});

it('rebuilds all managed connections when the event carries no database', function () {
    config()->set('truss.enabled', true);

    event(new MigrationsEnded('up'));

    // With no configured connections, the default (testing) is the managed one.
    expect(app(SchemaCacheRepository::class)->has('testing'))->toBeTrue();
});

it('rebuilds via the truss:rebuild command regardless of enabled', function () {
    config()->set('truss.enabled', false);

    $this->artisan('truss:rebuild')->assertSuccessful();

    expect(app(SchemaCacheRepository::class)->has('testing'))->toBeTrue();
});

it('rebuilds a specific connection via --connection', function () {
    $this->artisan('truss:rebuild', ['--connection' => 'testing'])
        ->assertSuccessful();

    expect(app(SchemaCacheRepository::class)->has('testing'))->toBeTrue();
});

it('captures the pre-migration snapshot as the diff baseline', function () {
    config()->set('truss.enabled', true);
    config()->set('truss.diff.enabled', true);
    Storage::fake('local');

    $repo = app(SchemaCacheRepository::class);
    $store = app(BaselineStore::class);

    $before = $repo->get('testing');
    expect($store->has('testing'))->toBeFalse();

    $this->travel(2)->seconds();
    event(new MigrationsEnded('up', ['database' => 'testing']));

    // The baseline holds exactly the pre-migration snapshot, and current moved on.
    expect($store->get('testing'))->toBe($before)
        ->and($repo->get('testing')['generated_at'])->not->toBe($before['generated_at']);
});

it('does not write a baseline when nothing was cached before the migration', function () {
    config()->set('truss.enabled', true);
    config()->set('truss.diff.enabled', true);
    Storage::fake('local');

    event(new MigrationsEnded('up', ['database' => 'testing']));

    expect(app(BaselineStore::class)->has('testing'))->toBeFalse()
        ->and(app(SchemaCacheRepository::class)->has('testing'))->toBeTrue();
});

it('writes no baseline when schema diff is disabled, but still rebuilds', function () {
    config()->set('truss.enabled', true);
    config()->set('truss.diff.enabled', false);
    Storage::fake('local');

    $repo = app(SchemaCacheRepository::class);
    $repo->get('testing');

    event(new MigrationsEnded('up', ['database' => 'testing']));

    expect(app(BaselineStore::class)->has('testing'))->toBeFalse()
        ->and($repo->has('testing'))->toBeTrue();
});

it('does not break migrations when the baseline disk is unavailable', function () {
    // The listener runs on MigrationsEnded, and Laravel does not swallow listener
    // exceptions, so an unwritable baseline disk would fail `php artisan migrate`
    // itself. A dev tool must never be able to break the host app's migrations.
    config()->set('truss.enabled', true);
    config()->set('truss.diff.disk', 'not-a-real-disk');

    $repo = app(SchemaCacheRepository::class);
    $repo->rebuild('testing'); // seed a cached snapshot, so a baseline capture is attempted

    event(new MigrationsEnded('up', ['database' => 'testing']));

    // The rebuild still happened: the baseline failure did not abort the listener.
    expect($repo->has('testing'))->toBeTrue();
});
