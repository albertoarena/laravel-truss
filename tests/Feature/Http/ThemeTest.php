<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    config()->set('truss.enabled', true);
    Gate::define('viewTruss', fn ($user = null) => true);
});

it('serves the theme stylesheet with a css content type', function () {
    config()->set('truss.theme.colors.light.accent', '#3730a3');

    $response = $this->get('/truss/theme.css')->assertOk();

    expect($response->headers->get('content-type'))->toContain('css');
});

it('emits the configured overrides mapped to their tokens, in both modes', function () {
    config()->set('truss.theme', [
        'colors' => [
            'light' => ['accent' => '#3730a3'],
            'dark' => ['background' => '#0b1020'],
        ],
    ]);

    $css = $this->get('/truss/theme.css')->assertOk()->getContent();

    expect($css)->toContain('--bp-ink: #3730a3;')
        ->and($css)->toContain('--bp-focus-border: #3730a3;')
        ->and($css)->toContain(':root[data-theme="dark"]')
        ->and($css)->toContain('--bp-bg: #0b1020;');
});

it('serves an empty body when no custom theme is configured', function () {
    // The shipped default has empty colours and null fonts.
    $css = $this->get('/truss/theme.css')->assertOk()->getContent();

    expect($css)->toBe('');
});

it('caches in production but never in debug (so local edits show)', function () {
    config()->set('truss.theme.colors.light.accent', '#3730a3');

    config()->set('app.debug', false);
    expect($this->get('/truss/theme.css')->assertOk()->headers->get('cache-control'))->toContain('max-age');

    config()->set('app.debug', true);
    expect($this->get('/truss/theme.css')->assertOk()->headers->get('cache-control'))->toContain('no-store');
});

it('links the theme stylesheet from the shell only when a custom theme is configured', function () {
    // Default install: no theme link, so no extra request.
    $this->get('/truss')->assertOk()->assertDontSee('/truss/theme.css', false);

    // Configured: the shell links the generated sheet after the base stylesheet.
    config()->set('truss.theme.colors.light.accent', '#3730a3');
    $this->get('/truss')->assertOk()->assertSee(route('truss.theme'), false);
});

it('gates the theme stylesheet so it never confirms Truss exists', function () {
    Gate::define('viewTruss', fn ($user = null) => false);

    $this->get('/truss/theme.css')->assertNotFound();
});

it('404s the theme stylesheet when Truss is disabled', function () {
    config()->set('truss.enabled', false);

    $this->get('/truss/theme.css')->assertNotFound();
});
