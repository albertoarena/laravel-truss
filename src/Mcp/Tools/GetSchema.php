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

#[Name('get_schema')]
#[Description('Get the whole database structure in a chosen format (dbml, json, csv, markdown, mermaid, or llm), optionally compact and limited to specific tables. Structure only, never row data.')]
class GetSchema extends Tool
{
    public function handle(Request $request): Response
    {
        $format = (string) $request->get('format', '') ?: (string) config('truss.export.default_format', 'dbml');
        if (! SchemaExporter::supports($format)) {
            return Response::error("Unknown format [{$format}]. Supported: ".implode(', ', SchemaExporter::formats()).'.');
        }

        $connection = (string) $request->get('connection', '');
        $tables = array_values(array_filter((array) $request->get('tables', []), 'is_string'));

        try {
            $builder = app(TrussManager::class)->snapshot();
            if ($connection !== '') {
                $builder = $builder->connection($connection);
            }
            if ($tables !== []) {
                $builder = $builder->only($tables);
            }
            if ($request->get('compact')) {
                $builder = $builder->compact();
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
            'format' => $schema->string()
                ->description('dbml, json, csv, markdown, mermaid, or llm. Defaults to truss.export.default_format.'),
            'compact' => $schema->boolean()
                ->description('Drop defaults and non-unique indexes to shrink the output.'),
            'tables' => $schema->array()
                ->description('Limit the export to these table names.'),
            'connection' => $schema->string()
                ->description('The managed connection to read. Defaults to the application default connection.'),
        ];
    }
}
