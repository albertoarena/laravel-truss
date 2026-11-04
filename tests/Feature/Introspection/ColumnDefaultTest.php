<?php

declare(strict_types=1);

use AlbertoArena\Truss\Introspection\SnapshotBuilder;
use Illuminate\Support\Facades\Schema;

/*
 * MariaDB reports a nullable column with no default as having the default
 * NULL, spelled as the bare keyword, where every other driver reports no
 * default at all. Other defaults stay as each database reports them: MariaDB,
 * SQLite and Postgres write a string default as an SQL literal ('draft'), and
 * MySQL as the bare value. These run on every lane and fail only on MariaDB.
 */

function defaultsOf(string $table): array
{
    $found = collect((new SnapshotBuilder)->introspect('testing'))->firstWhere('name', $table);

    return collect($found->columns)->mapWithKeys(fn ($c) => [$c->name => $c->default])->all();
}

beforeEach(function () {
    Schema::create('drafts', function ($table) {
        $table->id();
        $table->string('note')->nullable();
        $table->string('word')->default('NULL');
    });
});

it('reports no default on a nullable column without one', function () {
    expect(defaultsOf('drafts')['note'])->toBeNull();
});

it('keeps a string default that is the word NULL', function () {
    expect(defaultsOf('drafts')['word'])->not->toBeNull();
});
