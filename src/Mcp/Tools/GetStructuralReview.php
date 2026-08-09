<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Mcp\Tools;

use AlbertoArena\Truss\Doctor\DoctorReport;
use AlbertoArena\Truss\TrussManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * A thin wrapper over the deterministic `truss:doctor` review, surfacing the
 * same findings (a table with no primary key, an unindexed foreign key, and so
 * on) over MCP. Deterministic and structure only: it never reads row data and
 * makes no model or network call.
 */
#[Name('get_structural_review')]
#[Description('Run the deterministic structural review: problems visible from structure alone, such as a table with no primary key or an unindexed foreign key. Returns a severity summary and the findings. Structure only, no row data.')]
class GetStructuralReview extends Tool
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

        $name = $connection !== '' ? $connection : (string) config('database.default');
        $doctor = app(DoctorReport::class);

        // The tables are already exclusion-filtered by the builder; the doctor
        // applies its own excludes on top.
        return Response::json($doctor->toArray($doctor->for($name, ['tables' => $tables])));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'connection' => $schema->string()
                ->description('The managed connection to review. Defaults to the application default connection.'),
        ];
    }
}
