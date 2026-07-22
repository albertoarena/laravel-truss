<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Introspection\SnapshotBuilder;

it('builds, stamps generated_at, and caches the snapshot', function () {
    $snapshot = (new SchemaCacheRepository)->get('testing');

    expect($snapshot)->toHaveKey('generated_at')
        ->and($snapshot['generated_at'])->toBeString()->not->toBeEmpty()
        ->and($snapshot['connection'])->toBe('testing')
        ->and($snapshot['tables'])->toBeArray();
});

it('stamps generated_at only in the cache layer, not the builder', function () {
    // The introspection layer must stay deterministic; the timestamp is added here.
    $builderSnapshot = (new SnapshotBuilder)->build('testing');

    expect($builderSnapshot)->not->toHaveKey('generated_at');
});

it('serves the cached snapshot and only refreshes on rebuild', function () {
    $repo = new SchemaCacheRepository;

    $first = $repo->get('testing');

    $this->travel(5)->seconds();

    expect($repo->get('testing')['generated_at'])->toBe($first['generated_at']);

    $rebuilt = $repo->rebuild('testing');

    expect($rebuilt['generated_at'])->not->toBe($first['generated_at'])
        ->and($repo->get('testing')['generated_at'])->toBe($rebuilt['generated_at']);
});

it('respects the configured cache ttl', function () {
    config()->set('truss.cache.ttl', 60);
    $repo = new SchemaCacheRepository;

    $repo->get('testing');
    expect($repo->has('testing'))->toBeTrue();

    $this->travel(61)->seconds();
    expect($repo->has('testing'))->toBeFalse();
});

it('caches per connection under a distinct key', function () {
    $repo = new SchemaCacheRepository;

    expect($repo->key('mysql'))->not->toBe($repo->key('pgsql'))
        ->and($repo->key('mysql'))->toContain('mysql');
});

it('forgets a cached snapshot', function () {
    $repo = new SchemaCacheRepository;

    $repo->get('testing');
    $repo->forget('testing');

    expect($repo->has('testing'))->toBeFalse();
});
