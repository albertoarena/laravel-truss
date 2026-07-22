<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use Illuminate\Database\Events\MigrationsEnded;

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
