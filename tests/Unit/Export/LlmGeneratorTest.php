<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\Annotator;
use AlbertoArena\Truss\Export\LlmGenerator;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

function llmTables(): array
{
    return SchemaBuilder::make()
        ->table('users', function ($t) {
            $t->id();
            $t->string('email');
            $t->unique('email');
        })
        ->table('posts', function ($t) {
            $t->id();
            $t->foreignId('user_id')->on('users');
            $t->integer('status');
            $t->softDeletes();
        })
        ->build()['tables'];
}

it('emits a header with the table count', function () {
    expect((new LlmGenerator)->generate(llmTables()))->toStartWith("# 2 tables\n");
});

it('renders global notes as header note lines', function () {
    $out = (new LlmGenerator)->generate(llmTables(), ['All timestamps are UTC.']);

    expect($out)->toContain('# note: All timestamps are UTC.');
});

it('marks pk, unique, nullability, and inline foreign keys', function () {
    $out = (new LlmGenerator)->generate(llmTables());

    expect($out)->toContain('  id bigint unsigned pk')
        ->and($out)->toContain('  email varchar(255) unique')
        ->and($out)->toContain('  user_id bigint unsigned -> users.id')
        ->and($out)->toContain('  deleted_at timestamp null'); // nullable marked, others bare
});

it('indents a column annotation beneath its column and a table note on the heading', function () {
    $tables = (new Annotator(
        tableNotes: ['posts' => 'One row per post.'],
        columnNotes: ['posts.status' => '0 draft, 1 paid'],
    ))->annotate(llmTables());

    $out = (new LlmGenerator)->generate($tables);

    expect($out)->toContain('posts  -- One row per post.')
        ->and($out)->toContain("  status int\n    # 0 draft, 1 paid");
});

it('has no trailing whitespace on any line and is deterministic', function () {
    $out = (new LlmGenerator)->generate(llmTables(), ['a note']);

    expect($out)->not->toMatch('/ \n/')
        ->and($out)->not->toEndWith("\n")
        ->and((new LlmGenerator)->generate(llmTables(), ['a note']))->toBe($out);
});
