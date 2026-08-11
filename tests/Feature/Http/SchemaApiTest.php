<?php

declare(strict_types=1);

use AlbertoArena\Truss\Diff\BaselineStore;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('truss.enabled', true);
    config()->set('truss.diff.enabled', true);
    Gate::define('viewTruss', fn ($user = null) => true);
});

function apiBaseline(array $tables): void
{
    app(BaselineStore::class)->save('testing', [
        'connection' => 'testing',
        'generated_at' => '2026-07-01T00:00:00+00:00',
        'tables' => $tables,
    ]);
}

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

it('404s the API when the viewTruss gate denies', function () {
    Gate::define('viewTruss', fn ($user = null) => false);

    $this->getJson('/truss/api/schema')->assertNotFound();
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

it('embeds a null diff when no baseline has been recorded', function () {
    Storage::fake('local');
    Schema::create('posts', fn ($table) => $table->id());

    $this->getJson('/truss/api/schema')
        ->assertOk()
        ->assertJsonPath('diff', null);
});

it('embeds the structural diff when a baseline exists', function () {
    Storage::fake('local');
    Schema::create('posts', function ($table) {
        $table->id();
        $table->string('title');
    });

    // Baseline: posts without `title`, plus a `legacy` table now gone.
    apiBaseline([
        ['name' => 'posts', 'columns' => [
            ['name' => 'id', 'type' => 'bigint unsigned', 'nullable' => false, 'default' => null],
        ], 'primary_key' => ['id'], 'indexes' => [], 'foreign_keys' => []],
        ['name' => 'legacy', 'columns' => [], 'primary_key' => [], 'indexes' => [], 'foreign_keys' => []],
    ]);

    $diff = $this->getJson('/truss/api/schema')->assertOk()->json('diff');

    expect($diff['has_changes'])->toBeTrue()
        ->and($diff['tables_removed'])->toBe([['name' => 'legacy']]);

    $posts = collect($diff['tables_changed'])->firstWhere('name', 'posts');
    expect($posts['columns_added'][0]['name'])->toBe('title');
});

it('embeds a null diff when the feature is disabled even if a baseline exists', function () {
    config()->set('truss.diff.enabled', false);
    Storage::fake('local');
    Schema::create('posts', fn ($table) => $table->id());
    apiBaseline([['name' => 'legacy', 'columns' => [], 'primary_key' => [], 'indexes' => [], 'foreign_keys' => []]]);

    $this->getJson('/truss/api/schema')->assertOk()->assertJsonPath('diff', null);
});

it('embeds doctor findings for the connection', function () {
    // A table with no primary key trips the high-confidence INT-001 rule.
    Schema::create('ledger', fn ($table) => $table->string('note'));

    $doctor = $this->getJson('/truss/api/schema')->assertOk()->json('doctor');

    expect($doctor['summary']['total'])->toBeGreaterThanOrEqual(1);

    $finding = collect($doctor['findings'])->firstWhere('code', 'TRUSS-INT-001');
    expect($finding)->not->toBeNull()
        ->and($finding['table'])->toBe('ledger')
        ->and($finding['severity'])->toBe('error')
        ->and($finding['confidence'])->toBe('high')
        ->and($finding['category'])->toBe('integrity');
});

it('embeds a null doctor payload when the dashboard panel is disabled', function () {
    config()->set('truss.doctor.dashboard', false);
    Schema::create('ledger', fn ($table) => $table->string('note'));

    $this->getJson('/truss/api/schema')->assertOk()->assertJsonPath('doctor', null);
});

it('keeps excluded tables out of doctor findings', function () {
    config()->set('truss.excluded_tables', ['ledger']);
    Schema::create('ledger', fn ($table) => $table->string('note'));
    Schema::create('posts', fn ($table) => $table->id());

    $response = $this->getJson('/truss/api/schema')->assertOk();

    $tables = collect($response->json('doctor.findings'))->pluck('table');
    expect($tables)->not->toContain('ledger');
    expect($response->getContent())->not->toContain('ledger');
});

it('keeps excluded tables out of the embedded diff', function () {
    config()->set('truss.excluded_tables', ['sessions']);
    Storage::fake('local');
    Schema::create('posts', fn ($table) => $table->id());

    // Baseline held a now-excluded `sessions` table: it must not surface as a diff.
    apiBaseline([['name' => 'sessions', 'columns' => [], 'primary_key' => [], 'indexes' => [], 'foreign_keys' => []]]);

    $response = $this->getJson('/truss/api/schema')->assertOk();

    expect($response->getContent())->not->toContain('sessions');
});

it('still serves the schema when the diff baseline disk is unavailable', function () {
    // Field report, 2026-08-11: an app with FILESYSTEM_DISK=s3 got HTTP 500 from
    // this endpoint on a clean install, because reading the baseline went through
    // the S3 adapter and threw. The diagram needs no filesystem access at all, so
    // a baseline failure must never take the whole endpoint down.
    config()->set('truss.diff.disk', 'not-a-real-disk');
    Schema::create('posts', function ($table) {
        $table->id();
    });

    $response = $this->getJson('/truss/api/schema?connection=testing');

    $response->assertOk();
    expect($response->json('tables'))->not->toBeEmpty()
        ->and($response->json('diff'))->toBeNull()
        // Signalled separately, so `diff` keeps its existing null-or-object shape.
        ->and($response->json('diff_unavailable'))->toBeTrue();
});

it('does not claim the diff is unavailable when the disk is healthy', function () {
    $response = $this->getJson('/truss/api/schema?connection=testing');

    $response->assertOk();
    expect($response->json('diff_unavailable'))->toBeNull();
});
