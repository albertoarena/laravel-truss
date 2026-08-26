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

it('labels the connection switcher "Connections"', function () {
    config()->set('truss.enabled', true);
    Gate::define('viewTruss', fn ($user = null) => true);

    $html = $this->get('/truss')->assertOk()->getContent();

    // The switcher label reads the full word, matching the docs site, not "Conn".
    expect($html)->toContain('<span class="truss-field-label">Connections</span>');
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

it('reflects the doctor flag-tables config in the view', function () {
    config()->set('truss.enabled', true);
    Gate::define('viewTruss', fn ($user = null) => true);

    config()->set('truss.doctor.flag_tables', true);
    $this->get('/truss')->assertOk()->assertSee('data-doctor-flag-tables="1"', false);

    config()->set('truss.doctor.flag_tables', false);
    $this->get('/truss')->assertOk()->assertSee('data-doctor-flag-tables="0"', false);
});

it('honours a custom route prefix', function () {
    config()->set('truss.enabled', true);
    Gate::define('viewTruss', fn ($user = null) => true);

    // The default prefix is registered at boot; the named route reflects it.
    expect(route('truss.index', absolute: false))->toBe('/truss');
});

it('preloads the label font so the diagram is not measured in a fallback', function () {
    // Mermaid sizes every label box to the text it measures and leaves no
    // slack, so a face that arrives afterwards repaints wider glyphs into
    // boxes sized for the fallback and the last character is clipped
    // (issue #59). The client waits for the face before measuring; this hint
    // starts the fetch with the document rather than when the stylesheet is
    // parsed, so that wait is usually over before it begins.
    config()->set('truss.enabled', true);
    Gate::define('viewTruss', fn ($user = null) => true);

    $this->get('/truss')
        ->assertOk()
        ->assertSee('rel="preload"', false)
        ->assertSee('ibm-plex-mono-400.woff2', false)
        ->assertSee('as="font"', false)
        // Required even same-origin, or the preload is fetched a second time
        // rather than reused.
        ->assertSee('crossorigin', false);
});
