<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config()->set('truss.enabled', true);
    Gate::define('viewTruss', fn ($user = null) => true);
});

it('returns the cached schema JSON envelope for the default connection', function () {
    Schema::create('posts', function ($table) {
        $table->id();
        $table->string('title');
    });

    $this->getJson('/truss/api/schema')
        ->assertOk()
        ->assertJsonPath('connection', 'testing')
        ->assertJsonPath('fallback', false)
        ->assertJsonStructure([
            'connection',
            'fallback',
            'skipped_migrations',
            'generated_at',
            'tables' => [['name', 'columns', 'primary_key', 'indexes', 'foreign_keys']],
        ]);
});

it('never includes excluded tables in the response body', function () {
    Schema::create('posts', fn ($table) => $table->id());
    Schema::create('sessions', function ($table) {
        $table->string('id')->primary();
        $table->text('payload');
    });

    $response = $this->getJson('/truss/api/schema')->assertOk();

    $names = collect($response->json('tables'))->pluck('name');
    expect($names)->toContain('posts')
        ->and($names)->not->toContain('sessions');

    // The structure-only promise: the excluded table never reaches the wire at all.
    expect($response->getContent())->not->toContain('sessions');
});

it('applies per-connection excluded tables on top of the global list', function () {
    config()->set('truss.excluded_tables', []);
    config()->set('truss.connections', ['testing' => ['excluded_tables' => ['secret_audit']]]);

    Schema::create('posts', fn ($table) => $table->id());
    Schema::create('secret_audit', fn ($table) => $table->id());

    $names = collect($this->getJson('/truss/api/schema')->assertOk()->json('tables'))->pluck('name');

    expect($names)->toContain('posts')->and($names)->not->toContain('secret_audit');
});

it('forbids the API when the viewTruss gate denies', function () {
    Gate::define('viewTruss', fn ($user = null) => false);

    $this->getJson('/truss/api/schema')->assertForbidden();
});

it('404s the API when Truss is disabled', function () {
    config()->set('truss.enabled', false);

    $this->getJson('/truss/api/schema')->assertNotFound();
});

it('does not visualize a connection that is not managed by config', function () {
    config()->set('truss.connections', ['mysql' => []]);

    // 'testing' (the default connection) is not among the configured managed ones.
    $this->getJson('/truss/api/schema?connection=testing')->assertNotFound();
});
