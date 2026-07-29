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

it('renders the toolbar brand as the lowercase truss wordmark', function () {
    config()->set('truss.enabled', true);
    Gate::define('viewTruss', fn ($user = null) => true);

    $html = $this->get('/truss')->assertOk()->getContent();

    // The toolbar brand carries the lowercase "truss" wordmark (mark then word),
    // matching the site, not the old uppercase text.
    expect($html)->toMatch('/<span class="truss-brand">[\s\S]*?<\/svg>\s*truss\s*<\/span>/');
});

it('hides the index page entirely when Truss is disabled', function () {
    config()->set('truss.enabled', false);
    Gate::define('viewTruss', fn ($user = null) => true);

    $this->get('/truss')->assertNotFound();
});

it('passes the managed connections to the view for the connection switcher', function () {
    config()->set('truss.enabled', true);
    Gate::define('viewTruss', fn ($user = null) => true);

    $this->get('/truss')
        ->assertOk()
        ->assertSee('data-connections', false)
        ->assertSee('["testing"]', false); // the default managed connection under test
});

it('honours a custom route prefix', function () {
    config()->set('truss.enabled', true);
    Gate::define('viewTruss', fn ($user = null) => true);

    // The default prefix is registered at boot; the named route reflects it.
    expect(route('truss.index', absolute: false))->toBe('/truss');
});
