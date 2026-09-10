<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Http\Controllers;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Dashboard\DashboardPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Serves the dashboard payload as JSON for the requested connection (defaulting
 * to the app's own).
 *
 * The payload itself is assembled by DashboardPayload, which is also what
 * `Truss::payload()` returns, so the two can never drift. What stays here is the
 * one thing that is genuinely about HTTP: an unmanaged connection is answered
 * with a 404 rather than an error page, so the endpoint never confirms which
 * connections exist.
 *
 * See DashboardPayload for the exclusion filtering, the embedded diff and doctor
 * report, and the two unavailability flags.
 */
class SchemaApiController
{
    public function __construct(
        private readonly SchemaCacheRepository $cache,
        private readonly DashboardPayload $payload,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $requested = $request->query('connection');
        $connection = is_string($requested) && $requested !== ''
            ? $requested
            : (string) config('database.default');

        abort_unless(in_array($connection, $this->cache->managedConnections(), true), 404);

        return response()->json($this->payload->for($connection));
    }
}
