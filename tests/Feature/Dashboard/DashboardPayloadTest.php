<?php

declare(strict_types=1);

use AlbertoArena\Truss\Diff\BaselineStore;
use AlbertoArena\Truss\Facades\Truss;
use AlbertoArena\Truss\Tests\Support\BrokenCacheStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The dashboard payload has exactly one producer, and `Truss::payload()` is the
 * way to reach it without HTTP.
 *
 * Every test here asserts the same thing from a different app state: what the
 * facade builds equals what `GET {prefix}/api/schema` serves. That equality is
 * the point. A caller inside the application (a Filament page, a Livewire
 * component, a console command) must not have to make an HTTP request to
 * itself to get the snapshot, the doctor findings and the diff together, and it
 * must never get a second, subtly different assembly of them.
 */
beforeEach(function () {
    config()->set('truss.enabled', true);
    config()->set('truss.diff.enabled', true);
    Gate::define('viewTruss', fn ($user = null) => true);

    // `generated_at` is stamped with now() at second precision, and every test
    // below builds the payload twice. Frozen so a tick between the two calls
    // cannot fail an assertion that is about assembly, not about clocks.
    $this->freezeTime();
});

function payloadBaseline(array $tables): void
{
    app(BaselineStore::class)->save('testing', [
        'connection' => 'testing',
        'generated_at' => '2026-07-01T00:00:00+00:00',
        'tables' => $tables,
    ]);
}

/**
 * The same payload from both producers: the JSON endpoint first, then the facade.
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function bothPayloads(?string $connection = null): array
{
    $query = $connection === null ? '' : '?connection='.$connection;

    $served = test()->getJson('/truss/api/schema'.$query)->assertOk()->json();

    return [$served, Truss::payload($connection)];
}

it('builds the payload the JSON endpoint serves', function () {
    Schema::create('posts', function ($table) {
        $table->id();
        $table->string('title');
    });

    [$served, $built] = bothPayloads();

    expect($built)->toEqual($served)
        // Guards the assertion above against passing on two empty payloads.
        ->and($built['tables'])->not->toBeEmpty()
        ->and($built['connection'])->toBe('testing');
});

it('builds the same payload when nothing has been cached by a request first', function () {
    // Reversed order: the facade runs on a cold cache and the endpoint second.
    // A payload that is only correct once an HTTP request has warmed something
    // is not usable by a caller that never makes one.
    Schema::create('posts', fn ($table) => $table->id());

    $built = Truss::payload();
    $served = $this->getJson('/truss/api/schema')->assertOk()->json();

    expect($built)->toEqual($served);
});

it('embeds the same diff the endpoint embeds', function () {
    Storage::fake('local');
    Schema::create('posts', fn ($table) => $table->id());
    payloadBaseline([]);

    [$served, $built] = bothPayloads();

    expect($built)->toEqual($served)
        ->and($built['diff'])->not->toBeNull();
});

it('embeds the same doctor findings the endpoint embeds', function () {
    // A table with no primary key trips the high-confidence INT-001 rule.
    Schema::create('ledger', fn ($table) => $table->string('note'));

    [$served, $built] = bothPayloads();

    expect($built)->toEqual($served)
        ->and($built['doctor']['summary']['total'])->toBeGreaterThanOrEqual(1);
});

it('omits the doctor payload for both producers when the panel is disabled', function () {
    config()->set('truss.doctor.dashboard', false);
    Schema::create('ledger', fn ($table) => $table->string('note'));

    [$served, $built] = bothPayloads();

    expect($built)->toEqual($served)
        ->and($built['doctor'])->toBeNull();
});

it('applies the exclusion list, so an excluded table reaches neither caller', function () {
    config()->set('truss.excluded_tables', ['sessions']);
    Schema::create('posts', fn ($table) => $table->id());
    Schema::create('sessions', function ($table) {
        $table->string('id')->primary();
        $table->text('payload');
    });

    [$served, $built] = bothPayloads();

    expect($built)->toEqual($served)
        ->and(collect($built['tables'])->pluck('name'))->toContain('posts')
        // The structure-only promise holds on this path too: an excluded table
        // is absent from the whole payload, not merely from the table list.
        ->and(json_encode($built))->not->toContain('sessions');
});

it('applies per-connection exclusions on top of the global list', function () {
    config()->set('truss.excluded_tables', []);
    config()->set('truss.connections', ['testing' => ['excluded_tables' => ['secret_audit']]]);
    Schema::create('posts', fn ($table) => $table->id());
    Schema::create('secret_audit', fn ($table) => $table->id());

    [$served, $built] = bothPayloads('testing');

    expect($built)->toEqual($served)
        ->and(collect($built['tables'])->pluck('name'))->not->toContain('secret_audit');
});

it('defaults to the application default connection, as the route does', function () {
    Schema::create('posts', fn ($table) => $table->id());

    expect(Truss::payload())->toEqual(Truss::payload('testing'));
});

it('rejects a connection Truss does not manage', function () {
    config()->set('truss.connections', ['mysql' => []]);

    // The route answers this with a 404, which a facade cannot do. Refusing
    // loudly is the same answer in the language available here: asking for an
    // unmanaged connection is a programming error, not an empty result.
    Truss::payload('testing');
})->throws(InvalidArgumentException::class, 'testing');

it('flags an unavailable cache store exactly as the endpoint does', function () {
    Cache::extend('broken', fn () => Cache::repository(new BrokenCacheStore));
    config()->set('cache.stores.broken', ['driver' => 'broken']);
    config()->set('cache.default', 'broken');

    Schema::create('posts', fn ($table) => $table->id());

    [$served, $built] = bothPayloads();

    expect($built)->toEqual($served)
        ->and($built['cache_unavailable'])->toBeTrue()
        ->and($built['tables'])->not->toBeEmpty();
});

it('flags an unreadable diff baseline exactly as the endpoint does', function () {
    config()->set('truss.diff.disk', 'not-a-real-disk');
    Schema::create('posts', fn ($table) => $table->id());

    [$served, $built] = bothPayloads();

    expect($built)->toEqual($served)
        ->and($built['diff'])->toBeNull()
        ->and($built['diff_unavailable'])->toBeTrue();
});

it('omits both unavailable flags when nothing is wrong', function () {
    Schema::create('posts', fn ($table) => $table->id());

    [$served, $built] = bothPayloads();

    expect($built)->toEqual($served)
        ->and($built)->not->toHaveKey('cache_unavailable')
        ->and($built)->not->toHaveKey('diff_unavailable');
});
