<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\CsvGenerator;
use AlbertoArena\Truss\Export\DbmlGenerator;
use AlbertoArena\Truss\Export\JsonGenerator;
use AlbertoArena\Truss\Export\LlmGenerator;
use AlbertoArena\Truss\Export\MarkdownGenerator;
use AlbertoArena\Truss\Export\MermaidGenerator;
use AlbertoArena\Truss\Export\SchemaExporter;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

/**
 * The HTML generator is the first one with constructor dependencies, so
 * SchemaExporter stops doing `new $generator` and starts resolving. This pins
 * that the six formats that predate it still get a plainly-constructed
 * generator and that the exporter returns its bytes verbatim, which is the
 * regression the change risks and the one nobody would notice by eye.
 */
function constructionFixture(): array
{
    return SchemaBuilder::make()
        ->table('users', fn ($t) => $t->id()->string('email'))
        ->table('posts', fn ($t) => $t->id()->foreignId('user_id', 'users'))
        ->build()['tables'];
}

it('returns exactly what a plainly constructed generator produces', function (string $format, string $class) {
    $tables = (new SchemaExporter)->tablesFor(constructionFixture());

    expect((new SchemaExporter)->generate($format, $tables))
        ->toBe((new $class)->generate($tables));
})->with([
    ['dbml', DbmlGenerator::class],
    ['json', JsonGenerator::class],
    ['csv', CsvGenerator::class],
    ['markdown', MarkdownGenerator::class],
    ['mermaid', MermaidGenerator::class],
    ['llm', LlmGenerator::class],
]);
