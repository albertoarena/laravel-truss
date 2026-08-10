<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Mcp\Tools;

use AlbertoArena\Truss\TrussManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('describe_table')]
#[Description('Describe one table: its columns (name, type, nullability, default), primary key, indexes, foreign keys, and any annotations. Structure only, never row data.')]
#[IsReadOnly]
class DescribeTable extends Tool
{
    public function handle(Request $request): Response
    {
        $table = (string) $request->get('table', '');
        if ($table === '') {
            return Response::error('The `table` argument is required.');
        }

        $connection = (string) $request->get('connection', '');

        try {
            $builder = app(TrussManager::class)->snapshot()->only([$table]);
            if ($connection !== '') {
                $builder = $builder->connection($connection);
            }
            $tables = $builder->toArray();
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        // Empty means the table is unknown, excluded, or on another connection.
        if ($tables === []) {
            return Response::error("No table [{$table}] in this connection's structure.");
        }

        return Response::json($tables[0]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'table' => $schema->string()
                ->description('The table to describe.')
                ->required(),
            'connection' => $schema->string()
                ->description('The managed connection to read. Defaults to the application default connection.'),
        ];
    }
}
