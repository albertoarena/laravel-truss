<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    config()->set('truss.enabled', true);
    Gate::define('viewTruss', fn ($user = null) => true);
});

it('serves the app module with a javascript content type', function () {
    $response = $this->get('/truss/assets/truss.js')->assertOk();

    expect($response->headers->get('content-type'))->toContain('javascript');
});

it('serves the stylesheet with a css content type', function () {
    $response = $this->get('/truss/assets/truss.css')->assertOk();

    expect($response->headers->get('content-type'))->toContain('css');
});

it('serves the vendored Mermaid library locally (no CDN needed)', function () {
    $response = $this->get('/truss/assets/mermaid.min.js')->assertOk();

    expect($response->headers->get('content-type'))->toContain('javascript');
});

it('caches assets in production but never in debug (so local edits show)', function () {
    config()->set('app.debug', false);
    expect($this->get('/truss/assets/truss.js')->assertOk()->headers->get('cache-control'))->toContain('max-age');

    config()->set('app.debug', true);
    expect($this->get('/truss/assets/truss.js')->assertOk()->headers->get('cache-control'))->toContain('no-store');
});

it('404s any file not on the allow-list', function () {
    $this->get('/truss/assets/secrets.env')->assertNotFound();
    $this->get('/truss/assets/composer.json')->assertNotFound();
});

it('gates the assets too, so they never confirm Truss exists to the unauthorized', function () {
    Gate::define('viewTruss', fn ($user = null) => false);

    $this->get('/truss/assets/truss.js')->assertNotFound();
});

it('404s the assets when Truss is disabled', function () {
    config()->set('truss.enabled', false);

    $this->get('/truss/assets/truss.js')->assertNotFound();
});
