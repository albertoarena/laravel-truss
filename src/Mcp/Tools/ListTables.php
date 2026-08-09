<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Mcp\Tools;

use AlbertoArena\Truss\TrussManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List the database tables, each with a one-line structural summary: column count, whether it has a primary key, and foreign-key count. Structure only, never row data.')]
class ListTables extends Tool
{
    public function handle(Request $request): Response
    {
        $connection = (string) $request->get('connection', '');

        try {
            $builder = app(TrussManager::class)->snapshot();
            if ($connection !== '') {
                $builder = $builder->connection($connection);
            }
            $tables = $builder->toArray();
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        if ($tables === []) {
            return Response::text('No tables.');
        }

        $lines = array_map(function (array $table): string {
            $columns = count($table['columns'] ?? []);
            $hasPk = ($table['primary_key'] ?? []) !== [] ? 'has PK' : 'no PK';
            $fks = count($table['foreign_keys'] ?? []);

            return "{$table['name']}: {$columns} columns, {$hasPk}, {$fks} FK".($fks === 1 ? '' : 's');
        }, $tables);

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'connection' => $schema->string()
                ->description('The managed connection to read. Defaults to the application default connection.'),
        ];
    }
}
