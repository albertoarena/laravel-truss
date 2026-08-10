<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Mcp\Tools;

use AlbertoArena\Truss\Export\SchemaExporter;
use AlbertoArena\Truss\TrussManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('focus_table')]
#[Description('Get one table and its foreign-key neighbourhood (out to a given depth) in a chosen format. Useful for grounding on a slice of the schema. Structure only, never row data.')]
#[IsReadOnly]
class FocusTable extends Tool
{
    public function handle(Request $request): Response
    {
        $table = (string) $request->get('table', '');
        if ($table === '') {
            return Response::error('The `table` argument is required.');
        }

        $format = (string) $request->get('format', '') ?: (string) config('truss.export.default_format', 'dbml');
        if (! SchemaExporter::supports($format)) {
            return Response::error("Unknown format [{$format}]. Supported: ".implode(', ', SchemaExporter::formats()).'.');
        }

        $connection = (string) $request->get('connection', '');
        $depth = $request->get('depth') !== null ? (int) $request->get('depth') : null;

        try {
            $builder = app(TrussManager::class)->snapshot()->focus($table, $depth);
            if ($connection !== '') {
                $builder = $builder->connection($connection);
            }

            return Response::text($builder->render($format));
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'table' => $schema->string()
                ->description('The table at the centre of the neighbourhood.')
                ->required(),
            'depth' => $schema->integer()
                ->description('How many foreign-key hops of neighbours to include. Defaults to truss.focus.default_depth.'),
            'format' => $schema->string()
                ->description('dbml, json, csv, markdown, mermaid, or llm. Defaults to truss.export.default_format.'),
            'connection' => $schema->string()
                ->description('The managed connection to read. Defaults to the application default connection.'),
        ];
    }
}
