<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Http\Controllers;

use AlbertoArena\Truss\Export\SchemaExporter;
use AlbertoArena\Truss\TrussManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * Serves a structural export in the requested format, driving the same
 * ExportBuilder the command and facade use, so the dashboard download, the CLI,
 * and a scripted call all produce identical bytes. This is the server side of
 * the frontend swap: the browser no longer generates structural formats itself.
 *
 * Gated with the rest of the dashboard (the `viewTruss` gate runs in the route
 * group), and it inherits every safeguard from the builder: the managed-
 * connection allow-list, the `excluded_tables` merge, and structure-only output.
 * A malformed request (unknown format, unmanaged connection, missing focus
 * table) 404s rather than confirming anything.
 */
class ExportController
{
    private const MIME = [
        'dbml' => 'text/plain',
        'json' => 'application/json',
        'csv' => 'text/csv',
        'markdown' => 'text/markdown',
        'mermaid' => 'text/plain',
        'llm' => 'text/plain',
    ];

    public function __invoke(Request $request, TrussManager $truss, string $format): Response
    {
        abort_unless(SchemaExporter::supports($format), 404);

        $builder = $truss->snapshot()
            ->only($this->list($request, 'only'))
            ->except($this->list($request, 'except'));

        $connection = (string) $request->query('connection', '');
        if ($connection !== '') {
            $builder = $builder->connection($connection);
        }

        $focus = (string) $request->query('focus', '');
        if ($focus !== '') {
            $depth = $request->query('depth') !== null ? (int) $request->query('depth') : null;
            $builder = $builder->focus($focus, $depth);
        }

        if ($request->boolean('compact')) {
            $builder = $builder->compact();
        }

        if ($request->boolean('no_annotations')) {
            $builder = $builder->withoutAnnotations();
        }

        try {
            $content = $builder->render($format);
        } catch (InvalidArgumentException) {
            // An unmanaged connection or a missing focus table.
            abort(404);
        }

        return response($content, 200, ['Content-Type' => self::MIME[$format]]);
    }

    /**
     * Parse a comma-separated query parameter into a trimmed, non-empty list.
     *
     * @return list<string>
     */
    private function list(Request $request, string $key): array
    {
        $value = (string) $request->query($key, '');

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $v): bool => $v !== ''));
    }
}
