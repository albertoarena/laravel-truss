<?php

declare(strict_types=1);

use AlbertoArena\Truss\TrussServiceProvider;
use Illuminate\Support\ServiceProvider;

it('merges the truss config under the "truss" key with expected defaults', function () {
    expect(config('truss.route_prefix'))->toBe('truss')
        ->and(config('truss.enabled'))->toBeBool()
        ->and(config('truss.cache.ttl'))->toBe(3600)
        ->and(config('truss.connections'))->toBeArray()
        ->and(config('truss.excluded_tables'))->toBeArray()->toContain('sessions')
        ->and(config('truss.diagram.type_labels'))->toBe('native')
        ->and(config('truss.focus.default_depth'))->toBe(1)
        ->and(config('truss.large_schema.warn_above'))->toBe(60)
        ->and(config('truss.middleware'))->toBe(['web'])
        ->and(config('truss.authorization.allowed_emails'))->toBe([])
        ->and(config('truss.diagram.mermaid_url'))->toBeNull() // self-hosted by default
        ->and(config('truss.diagram.min_zoom'))->toBe(0.7);
});

it('follows the environment for revealing excluded tables', function () {
    // The same shape as `truss.enabled` and the `viewTruss` gate: local is
    // already open to whoever is looking, so the developer gets the reveal with
    // no configuration, and every other environment has to ask for it.
    expect(config('truss.reveal_excluded'))->toBeBool()
        ->and(config('truss.reveal_excluded'))->toBeFalse(); // the suite runs as `testing`
});

it('exposes no configurable gate name (the viewTruss ability is fixed)', function () {
    expect(config('truss.gate'))->toBeNull();
});

it('registers the config file as publishable', function () {
    $paths = ServiceProvider::pathsToPublish(TrussServiceProvider::class, 'truss-config');

    expect($paths)->not->toBeEmpty();
});
