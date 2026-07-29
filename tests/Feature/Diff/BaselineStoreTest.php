<?php

declare(strict_types=1);

use AlbertoArena\Truss\Diff\BaselineStore;
use Illuminate\Support\Facades\Storage;

function baselineSnapshot(string $connection = 'testing'): array
{
    return [
        'connection' => $connection,
        'generated_at' => '2026-07-29T10:00:00+00:00',
        'tables' => [
            ['name' => 'users', 'columns' => [['name' => 'id', 'type' => 'bigint unsigned', 'nullable' => false, 'default' => null]], 'primary_key' => ['id'], 'indexes' => [], 'foreign_keys' => []],
        ],
    ];
}

it('round-trips a snapshot through save and get', function () {
    Storage::fake('local');
    $store = new BaselineStore;

    $store->save('testing', baselineSnapshot());

    expect($store->get('testing'))->toBe(baselineSnapshot());
});

it('returns null and false when no baseline is stored', function () {
    Storage::fake('local');
    $store = new BaselineStore;

    expect($store->get('testing'))->toBeNull()
        ->and($store->has('testing'))->toBeFalse();
});

it('reports presence and forgets a stored baseline', function () {
    Storage::fake('local');
    $store = new BaselineStore;

    $store->save('testing', baselineSnapshot());
    expect($store->has('testing'))->toBeTrue();

    $store->forget('testing');
    expect($store->has('testing'))->toBeFalse()
        ->and($store->get('testing'))->toBeNull();
});

it('stores each connection independently', function () {
    Storage::fake('local');
    $store = new BaselineStore;

    $store->save('mysql', baselineSnapshot('mysql'));
    $store->save('pgsql', baselineSnapshot('pgsql'));

    expect($store->get('mysql')['connection'])->toBe('mysql')
        ->and($store->get('pgsql')['connection'])->toBe('pgsql');
});

it('slugifies connection names into a filesystem-safe path', function () {
    Storage::fake('local');
    $store = new BaselineStore;

    $store->save('tenant/main:1', baselineSnapshot('tenant/main:1'));

    expect($store->has('tenant/main:1'))->toBeTrue()
        ->and($store->get('tenant/main:1')['connection'])->toBe('tenant/main:1');

    // No path traversal: the slash must not create a nested directory.
    $files = Storage::disk('local')->allFiles('truss/baselines');
    expect($files)->toHaveCount(1)
        ->and($files[0])->not->toContain('tenant/main');
});

it('writes to the disk configured in truss.diff.disk', function () {
    config()->set('truss.diff.disk', 'baselines-disk');
    Storage::fake('baselines-disk');
    Storage::fake('local');
    $store = new BaselineStore;

    $store->save('testing', baselineSnapshot());

    expect(Storage::disk('baselines-disk')->allFiles('truss/baselines'))->toHaveCount(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(0);
});

it('uses the default disk when truss.diff.disk is null', function () {
    config()->set('truss.diff.disk', null);
    Storage::fake('local');
    $store = new BaselineStore;

    $store->save('testing', baselineSnapshot());

    expect(Storage::disk('local')->allFiles('truss/baselines'))->toHaveCount(1);
});
