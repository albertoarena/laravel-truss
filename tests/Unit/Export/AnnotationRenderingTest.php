<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\Annotator;
use AlbertoArena\Truss\Export\CsvGenerator;
use AlbertoArena\Truss\Export\DbmlGenerator;
use AlbertoArena\Truss\Export\JsonGenerator;
use AlbertoArena\Truss\Export\MarkdownGenerator;
use AlbertoArena\Truss\Export\MermaidGenerator;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

function annotatedTables(array $tableNotes = [], array $columnNotes = []): array
{
    $tables = SchemaBuilder::make()
        ->table('orders', function ($t) {
            $t->id();
            $t->integer('status');
        })
        ->build()['tables'];

    return (new Annotator(tableNotes: $tableNotes, columnNotes: $columnNotes))->annotate($tables);
}

it('renders a DBML column note, table note, and project note block', function () {
    $tables = annotatedTables(
        tableNotes: ['orders' => 'One row per order.'],
        columnNotes: ['orders.status' => '0 draft, 1 paid, 2 refunded'],
    );

    $out = (new DbmlGenerator)->generate($tables, ['All timestamps are UTC.']);

    expect($out)->toContain("status int [not null, note: '0 draft, 1 paid, 2 refunded']")
        ->and($out)->toContain("Note: 'One row per order.'")
        ->and($out)->toContain('Project truss {')
        ->and($out)->toContain('All timestamps are UTC.');
});

it('renders a Markdown Description column, table note, and notes intro', function () {
    $tables = annotatedTables(
        tableNotes: ['orders' => 'One row per order.'],
        columnNotes: ['orders.status' => 'enum of states'],
    );

    $out = (new MarkdownGenerator)->generate($tables, ['All timestamps are UTC.']);

    expect($out)->toContain('| Column | Type | Null | Default | Key | Description |')
        ->and($out)->toContain('enum of states')
        ->and($out)->toContain('> One row per order.')
        ->and($out)->toContain('> All timestamps are UTC.');
});

it('omits the Markdown Description column when nothing is annotated', function () {
    $out = (new MarkdownGenerator)->generate(annotatedTables());

    expect($out)->toContain('| Column | Type | Null | Default | Key |')
        ->and($out)->not->toContain('Description');
});

it('adds a CSV annotation column only when something is annotated', function () {
    $plain = (new CsvGenerator)->generate(annotatedTables());
    $annotated = (new CsvGenerator)->generate(annotatedTables(columnNotes: ['orders.status' => 'enum']));

    expect($plain)->toStartWith('table,name,type,nullable,default,key'."\n")
        ->and($plain)->not->toContain('annotation')
        ->and($annotated)->toContain('table,name,type,nullable,default,key,annotation')
        ->and($annotated)->toContain(',enum');
});

it('carries annotations through JSON as keys and leaves the array shape intact', function () {
    $annotated = json_decode((new JsonGenerator)->generate(
        annotatedTables(tableNotes: ['orders' => 'note'], columnNotes: ['orders.status' => 'enum']),
    ), true);
    $orders = collect($annotated)->firstWhere('name', 'orders');

    expect($annotated)->toBeArray()
        ->and($orders['annotation'])->toBe('note')
        ->and(collect($orders['columns'])->firstWhere('name', 'status')['annotation'])->toBe('enum');
});

it('never renders annotations or notes into Mermaid', function () {
    $out = (new MermaidGenerator)->generate(
        annotatedTables(tableNotes: ['orders' => 'note'], columnNotes: ['orders.status' => 'enum']),
        ['a global note'],
    );

    expect($out)->not->toContain('note')
        ->and($out)->not->toContain('global');
});

it('keeps quotes and newlines in an annotation from breaking DBML or CSV', function () {
    $tables = annotatedTables(columnNotes: ['orders.status' => "it's \"paid\"\nor draft"]);

    $dbml = (new DbmlGenerator)->generate($tables);
    $csv = (new CsvGenerator)->generate($tables);

    // DBML single-quoted note: quote escaped, newline flattened to a space.
    expect($dbml)->toContain("note: 'it\\'s \"paid\" or draft'")
        // CSV cell: wrapped in quotes, inner double-quotes doubled.
        ->and($csv)->toContain('"it\'s ""paid""'."\n".'or draft"');
});
