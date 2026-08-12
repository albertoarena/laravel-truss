<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\DatabaseCommentReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The one DB-touching part of the annotation path. On SQLite (local default) it
 * must return an empty map without error; on the MySQL/Postgres CI lanes it must
 * read real native table and column comments. The pure merge logic on top of it
 * is unit-tested with a faked map in AnnotatorTest.
 */

afterEach(function () {
    Schema::dropIfExists('commented');

    // Tear down the foreign namespace the scoping test creates, if it ran.
    match (DB::connection()->getDriverName()) {
        'mysql', 'mariadb' => DB::statement('drop database if exists truss_other_app'),
        'pgsql' => DB::statement('drop schema if exists other_app cascade'),
        default => null,
    };
});

it('returns an empty map on a driver without comment support', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('This asserts the no-support path; SQLite is that path.');
    }

    Schema::create('commented', function ($table) {
        $table->id();
        $table->string('status')->comment('ignored on sqlite');
    });

    expect((new DatabaseCommentReader)->read())->toBe([]);
});

it('reads native table and column comments where the driver supports them', function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Native comments need MySQL/Postgres; covered on those CI lanes.');
    }

    Schema::create('commented', function ($table) {
        $table->id();
        $table->string('status')->comment('0 draft, 1 paid');
        $table->comment('One row per thing.');
    });

    $comments = (new DatabaseCommentReader)->read();

    expect($comments['commented'])->toBe('One row per thing.')
        ->and($comments['commented.status'])->toBe('0 draft, 1 paid');
});

it('scopes the comment map to the connection schema', function () {
    // The same leak SnapshotBuilder guards against with getCurrentSchemaName()
    // (issue #3): since Laravel 12 a bare getTables() lists every schema on the
    // server, so on a shared host an unrelated database's comments land in the
    // map. Same-named tables then annotate ours with another app's meaning.
    $driver = DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        $this->markTestSkipped('The reader returns early on SQLite; covered on the MySQL/Postgres CI lanes.');
    }

    // A commented table in a namespace that is not the connection's own. On
    // MySQL that means a second database on the server; on Postgres, a second
    // schema in the same database, which is where a bare getTables() looks.
    if ($driver === 'mysql' || $driver === 'mariadb') {
        DB::statement('create database if not exists truss_other_app');
        DB::statement("create table truss_other_app.ghost (id int comment 'leaked column') comment 'leaked table'");
    } else {
        DB::statement('create schema if not exists other_app');
        DB::statement('create table other_app.ghost (id int)');
        DB::statement("comment on table other_app.ghost is 'leaked table'");
        DB::statement("comment on column other_app.ghost.id is 'leaked column'");
    }

    Schema::create('commented', function ($table) {
        $table->id();
        $table->string('status')->comment('0 draft, 1 paid');
        $table->comment('One row per thing.');
    });

    $comments = (new DatabaseCommentReader)->read();

    expect($comments)->toHaveKey('commented')          // our own schema is still read
        ->and($comments)->toHaveKey('commented.status')
        ->and($comments)->not->toHaveKey('ghost')      // the foreign namespace must not leak
        ->and($comments)->not->toHaveKey('ghost.id');
});

it('reads column comments on a connection with a table prefix', function () {
    // Issue #46, second site: the table comment comes off getTables() and is fine,
    // but getColumns() prepends the prefix to a name that already carries it, so
    // column comments silently vanish on any prefixed connection.
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Native comments need MySQL/Postgres; covered on those CI lanes.');
    }

    config(['database.connections.testing.prefix' => 'portal_']);
    DB::purge('testing');

    Schema::create('commented', function ($table) {
        $table->id();
        $table->string('status')->comment('0 draft, 1 paid');
        $table->comment('One row per thing.');
    });

    $comments = (new DatabaseCommentReader)->read();

    expect($comments['portal_commented'])->toBe('One row per thing.')
        ->and($comments['portal_commented.status'])->toBe('0 draft, 1 paid');
});
