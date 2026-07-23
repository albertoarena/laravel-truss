<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Http\Controllers;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns the cached schema snapshot as JSON for the requested connection
 * (defaulting to the app's default), with two invariants enforced here:
 *
 *   - Only connections Truss manages are visualizable; anything else 404s.
 *   - Config `excluded_tables` (global + per-connection) are filtered out
 *     server-side, at serve time, from the full cached snapshot — so excluded
 *     structure never reaches the client, and toggling exclusions needs no
 *     rebuild. This is the server-side half of the "no data exposed" promise.
 */
class SchemaApiController
{
    public function __construct(
        private readonly SchemaCacheRepository $cache,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $requested = $request->query('connection');
        $connection = is_string($requested) && $requested !== ''
            ? $requested
            : (string) config('database.default');

        abort_unless(in_array($connection, $this->cache->managedConnections(), true), 404);

        $snapshot = $this->cache->get($connection);
        $snapshot['tables'] = $this->withoutExcludedTables($snapshot['tables'], $connection);

        return response()->json($snapshot);
    }

    /**
     * Drop any table whose name is excluded, globally or for this connection.
     *
     * @param  list<array<string, mixed>>  $tables
     * @return list<array<string, mixed>>
     */
    private function withoutExcludedTables(array $tables, string $connection): array
    {
        $excluded = $this->excludedTablesFor($connection);

        return array_values(array_filter(
            $tables,
            fn (array $table): bool => ! in_array($table['name'], $excluded, true),
        ));
    }

    /**
     * The global exclusion list merged with this connection's overrides.
     *
     * @return list<string>
     */
    private function excludedTablesFor(string $connection): array
    {
        $global = (array) config('truss.excluded_tables', []);
        $perConnection = (array) config("truss.connections.{$connection}.excluded_tables", []);

        return array_values(array_unique([...$global, ...$perConnection]));
    }
}
