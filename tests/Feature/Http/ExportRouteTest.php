<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config()->set('truss.enabled', true);
    Gate::define('viewTruss', fn ($user = null) => true);

    Schema::create('users', function ($table) {
        $table->id();
        $table->string('email')->unique();
    });
    Schema::create('posts', function ($table) {
        $table->id();
        $table->foreignId('user_id')->constrained();
    });
});

it('serves each structural format', function () {
    expect($this->get('/truss/export/dbml')->assertOk()->getContent())->toContain('Table posts {')
        ->and($this->get('/truss/export/markdown')->assertOk()->getContent())->toContain('# Data dictionary')
        ->and($this->get('/truss/export/mermaid')->assertOk()->getContent())->toContain('erDiagram')
        ->and($this->get('/truss/export/llm')->assertOk()->getContent())->toContain('posts')
        ->and($this->get('/truss/export/csv')->assertOk()->getContent())->toStartWith('table,name,type');

    $json = $this->get('/truss/export/json')->assertOk();
    expect(collect($json->json())->pluck('name')->all())->toBe(['posts', 'users']);
});

it('sets a sensible content type per format', function () {
    expect($this->get('/truss/export/json')->headers->get('content-type'))->toContain('application/json')
        ->and($this->get('/truss/export/csv')->headers->get('content-type'))->toContain('text/csv')
        ->and($this->get('/truss/export/markdown')->headers->get('content-type'))->toContain('text/markdown');
});

it('produces output byte-identical to the command for the same filters', function () {
    foreach (['dbml', 'json', 'csv', 'markdown', 'mermaid', 'llm'] as $format) {
        Artisan::call('truss:export', ['--format' => $format, '--compact' => true, '--focus' => 'posts', '--depth' => '1']);
        $fromCommand = Artisan::output();

        $fromRoute = $this->get("/truss/export/{$format}?focus=posts&depth=1&compact=1")->assertOk()->getContent();

        expect($fromRoute)->toBe($fromCommand, "format {$format}");
    }
});

it('applies only, focus, and compact query filters', function () {
    expect(collect($this->get('/truss/export/json?only=users')->json())->pluck('name')->all())->toBe(['users'])
        ->and($this->get('/truss/export/dbml?compact=1')->getContent())->not->toContain('default:');

    $focus = collect($this->get('/truss/export/json?focus=posts&depth=1')->json())->pluck('name')->all();
    expect($focus)->toBe(['posts', 'users']);
});

it('never serves an excluded table', function () {
    config()->set('truss.excluded_tables', ['posts']);

    $body = $this->get('/truss/export/dbml')->assertOk()->getContent();

    expect($body)->toContain('Table users {')->not->toContain('Table posts {');
});

it('404s on an unknown format', function () {
    $this->get('/truss/export/yaml')->assertNotFound();
});

it('404s on an unmanaged connection', function () {
    $this->get('/truss/export/dbml?connection=nope')->assertNotFound();
});

it('404s on a missing focus table', function () {
    $this->get('/truss/export/dbml?focus=ghost')->assertNotFound();
});

it('denies access without the viewTruss gate', function () {
    Gate::define('viewTruss', fn ($user = null) => false);

    $this->get('/truss/export/dbml')->assertNotFound();
});
