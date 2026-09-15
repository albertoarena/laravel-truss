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

it('reports how many tables the exclusion list removed', function () {
    config()->set('truss.excluded_tables', ['sessions', 'audit_trail']);
    Schema::create('posts', fn ($table) => $table->id());
    Schema::create('sessions', fn ($table) => $table->string('id')->primary());
    Schema::create('audit_trail', fn ($table) => $table->id());

    [$served, $built] = bothPayloads();

    // A count, never the names. It is enough for the dashboard to say the view
    // is partial, and it betrays nothing about what was hidden.
    expect($built)->toEqual($served)
        ->and($built['excluded']['count'])->toBe(2)
        ->and(json_encode($built))->not->toContain('sessions');
});

it('counts what it actually removed, not the configured list length', function () {
    config()->set('truss.excluded_tables', ['sessions', 'no_such_table']);
    Schema::create('posts', fn ($table) => $table->id());
    Schema::create('sessions', fn ($table) => $table->string('id')->primary());

    // A configured name matching nothing must not inflate the count, or the
    // footer claims tables are hidden that never existed.
    expect(Truss::payload()['excluded']['count'])->toBe(1);
});

it('counts per-connection exclusions together with the global ones', function () {
    config()->set('truss.excluded_tables', ['sessions']);
    config()->set('truss.connections', ['testing' => ['excluded_tables' => ['secret_audit']]]);
    Schema::create('posts', fn ($table) => $table->id());
    Schema::create('sessions', fn ($table) => $table->string('id')->primary());
    Schema::create('secret_audit', fn ($table) => $table->id());

    expect(Truss::payload('testing')['excluded']['count'])->toBe(2);
});

it('reports a zero count rather than omitting it when nothing was excluded', function () {
    config()->set('truss.excluded_tables', []);
    Schema::create('posts', fn ($table) => $table->id());

    // Present at zero on purpose. The two `*_unavailable` keys appear only when
    // true because they are notices; a count is data, and a client should not
    // have to tell "nothing hidden" from "a payload that predates this".
    expect(Truss::payload())->toHaveKey('excluded.count')
        ->and(Truss::payload()['excluded']['count'])->toBe(0);
});

it('keeps excluded tables out of the payload when revealing is off', function () {
    config()->set('truss.reveal_excluded', false);
    config()->set('truss.excluded_tables', ['sessions']);
    Schema::create('posts', fn ($table) => $table->id());
    Schema::create('sessions', fn ($table) => $table->string('id')->primary());

    [$served, $built] = bothPayloads();

    expect($built)->toEqual($served)
        ->and(json_encode($built))->not->toContain('sessions');
});

it('carries excluded tables marked, rather than removed, when revealing is on', function () {
    config()->set('truss.reveal_excluded', true);
    config()->set('truss.excluded_tables', ['sessions']);
    Schema::create('posts', fn ($table) => $table->id());
    Schema::create('sessions', fn ($table) => $table->string('id')->primary());

    [$served, $built] = bothPayloads();

    $sessions = collect($built['tables'])->firstWhere('name', 'sessions');

    // Marked, not removed: the client decides whether to draw them, and the
    // count still reports how many the config list matched.
    expect($built)->toEqual($served)
        ->and($sessions)->not->toBeNull()
        ->and($sessions['excluded'])->toBeTrue()
        ->and(collect($built['tables'])->firstWhere('name', 'posts'))->not->toHaveKey('excluded')
        ->and($built['excluded']['count'])->toBe(1);
});

it('keeps the diff on the filtered set while revealing', function () {
    config()->set('truss.reveal_excluded', true);
    config()->set('truss.excluded_tables', ['sessions']);
    Schema::create('posts', fn ($table) => $table->id());
    Schema::create('sessions', fn ($table) => $table->string('id')->primary());
    payloadBaseline([['name' => 'posts', 'columns' => [], 'primary_key' => [], 'indexes' => [], 'foreign_keys' => []]]);

    $built = Truss::payload();

    // The baseline is filtered, so a revealed table diffed against it would be
    // reported as newly added on every single load. Revealing changes what is
    // drawn, never what is said to have changed.
    expect(json_encode($built['diff']))->not->toContain('sessions');
});

it('keeps the doctor on the filtered set while revealing', function () {
    config()->set('truss.reveal_excluded', true);
    config()->set('truss.excluded_tables', ['sessions']);
    Schema::create('posts', fn ($table) => $table->id());
    Schema::create('sessions', fn ($table) => $table->text('payload'));

    $built = Truss::payload();

    // A revealed table carries no findings: the doctor's scope is a
    // configuration question nobody has reopened.
    expect(json_encode($built['doctor']))->not->toContain('sessions');
});

it('reveals nothing extra over HTTP when asked by query parameter', function () {
    config()->set('truss.reveal_excluded', false);
    config()->set('truss.excluded_tables', ['sessions']);
    Schema::create('posts', fn ($table) => $table->id());
    Schema::create('sessions', fn ($table) => $table->string('id')->primary());

    // The gate is the operator's, and a query string is the viewer's. If this
    // ever passes, config exclusions have become advisory for anyone who can
    // reach the dashboard.
    $served = test()->getJson('/truss/api/schema?include_excluded=1')->assertOk()->json();

    expect(json_encode($served))->not->toContain('sessions');
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
