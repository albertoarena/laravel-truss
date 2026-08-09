<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Mcp\Resources;

use AlbertoArena\Truss\TrussManager;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Resource;

/**
 * The whole database structure as one readable resource: the "paste the entire
 * schema" convenience for grounding an agent. Compact and annotated, in the
 * configured default format. Structure only, never row data, and it honours the
 * same `excluded_tables` and managed-connection safeguards as everything else.
 */
#[Title('Database structure')]
#[Description('The whole database structure (tables, columns, keys, and annotations) as one compact, structure-only document.')]
class SchemaResource extends Resource
{
    protected string $uri = 'truss://schema';

    public function handle(Request $request): Response
    {
        $format = (string) config('truss.export.default_format', 'dbml');

        return Response::text(
            app(TrussManager::class)->snapshot()->compact()->render($format),
        );
    }

    public function mimeType(): string
    {
        return match ((string) config('truss.export.default_format', 'dbml')) {
            'json' => 'application/json',
            'csv' => 'text/csv',
            'markdown' => 'text/markdown',
            default => 'text/plain',
        };
    }
}
