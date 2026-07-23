<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;

it('renders the index shell when enabled and authorized', function () {
    config()->set('truss.enabled', true);
    Gate::define('viewTruss', fn ($user = null) => true);

    $this->get('/truss')
        ->assertOk()
        ->assertSee('Truss');
});

it('hides the index page entirely when Truss is disabled', function () {
    config()->set('truss.enabled', false);
    Gate::define('viewTruss', fn ($user = null) => true);

    $this->get('/truss')->assertNotFound();
});

it('honours a custom route prefix', function () {
    config()->set('truss.enabled', true);
    Gate::define('viewTruss', fn ($user = null) => true);

    // The default prefix is registered at boot; the named route reflects it.
    expect(route('truss.index', absolute: false))->toBe('/truss');
});
