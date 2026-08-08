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
