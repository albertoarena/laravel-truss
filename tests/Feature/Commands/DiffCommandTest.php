<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Diff\BaselineStore;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('truss.diff.enabled', true);

    Schema::create('widgets', function ($table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('parts', function ($table) {
        $table->id();
        $table->foreignId('widget_id')->constrained();
    });
});

function saveBaseline(array $tables): void
{
    app(BaselineStore::class)->save('testing', [
        'connection' => 'testing',
        'generated_at' => '2026-07-01T00:00:00+00:00',
        'tables' => $tables,
    ]);
}

function baselineTable(string $name, array $columns): array
{
    return ['name' => $name, 'columns' => $columns, 'primary_key' => ['id'], 'indexes' => [], 'foreign_keys' => []];
}

it('prints added, removed and changed structure', function () {
    // Baseline had `legacy` (now gone) and a `widgets.name` of a different type,
    // and no `parts` (now present).
    saveBaseline([
        baselineTable('widgets', [
            ['name' => 'id', 'type' => 'bigint unsigned', 'nullable' => false, 'default' => null],
            ['name' => 'name', 'type' => 'varchar(100)', 'nullable' => false, 'default' => null],
        ]),
        baselineTable('legacy', [
            ['name' => 'id', 'type' => 'bigint unsigned', 'nullable' => false, 'default' => null],
        ]),
    ]);

    $this->artisan('truss:diff')
        ->assertSuccessful()
        ->expectsOutputToContain('parts')      // added table
        ->expectsOutputToContain('legacy')     // removed table
        ->expectsOutputToContain('widgets')    // changed table
        ->expectsOutputToContain('name');      // the changed column
});

it('reports when there are no structural changes', function () {
    // Cache the current snapshot, then use it verbatim as the baseline.
    $current = app(SchemaCacheRepository::class)->get('testing');
    app(BaselineStore::class)->save('testing', $current);

    $this->artisan('truss:diff')
        ->assertSuccessful()
        ->expectsOutputToContain('No structural changes');
});

it('reports when no baseline has been recorded yet', function () {
    $this->artisan('truss:diff')
        ->assertSuccessful()
        ->expectsOutputToContain('No baseline');
});

it('reports when the schema diff feature is disabled', function () {
    config()->set('truss.diff.enabled', false);
    saveBaseline([baselineTable('legacy', [])]);

    $this->artisan('truss:diff')
        ->assertSuccessful()
        ->expectsOutputToContain('disabled');
});

it('diffs a specific connection with --connection', function () {
    saveBaseline([baselineTable('legacy', [])]);

    $this->artisan('truss:diff', ['--connection' => 'testing'])
        ->assertSuccessful()
        ->expectsOutputToContain('legacy');
});
