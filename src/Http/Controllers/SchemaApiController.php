<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Http\Controllers;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Diff\BaselineStore;
use AlbertoArena\Truss\Diff\SchemaDiffer;
use AlbertoArena\Truss\Doctor\DoctorReport;
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
 *
 * When the schema-diff feature is enabled and a baseline exists, the response
 * also carries a `diff` describing what changed since the last migration. The
 * baseline is filtered through the same exclusion list before comparison, so an
 * excluded table can never surface via the diff either. `diff` is null when the
 * feature is off or no baseline has been recorded yet.
 */
class SchemaApiController
{
    public function __construct(
        private readonly SchemaCacheRepository $cache,
        private readonly BaselineStore $baselines = new BaselineStore,
        private readonly SchemaDiffer $differ = new SchemaDiffer,
        private readonly DoctorReport $doctor = new DoctorReport,
    ) {}

    /** Whether the diff baseline could not be read (a disk problem, not an absent file). */
    private bool $baselineUnavailable = false;

    public function __invoke(Request $request): JsonResponse
    {
        $requested = $request->query('connection');
        $connection = is_string($requested) && $requested !== ''
            ? $requested
            : (string) config('database.default');

        abort_unless(in_array($connection, $this->cache->managedConnections(), true), 404);

        $snapshot = $this->cache->get($connection);
        $snapshot['tables'] = $this->withoutExcludedTables($snapshot['tables'], $connection);
        $snapshot['diff'] = $this->diffFor($connection, $snapshot);
        $snapshot['doctor'] = $this->doctorFor($connection, $snapshot);

        // Signalled beside `diff` rather than inside it, so `diff` keeps its
        // null-or-object shape and existing clients need no change. Present only
        // when the baseline could not be read, so the dashboard can say the
        // feature is unavailable instead of silently showing no changes.
        if ($this->baselineUnavailable) {
            $snapshot['diff_unavailable'] = true;
        }

        return response()->json($snapshot);
    }

    /**
     * The doctor report for the connection, or null when the dashboard panel is
     * switched off. The snapshot is already exclusion-filtered, so excluded
     * tables never reach a finding. Structure only, like the rest of the response.
     *
     * @param  array<string, mixed>  $snapshot  the already exclusion-filtered current snapshot
     * @return array<string, mixed>|null
     */
    private function doctorFor(string $connection, array $snapshot): ?array
    {
        if (! config('truss.doctor.dashboard', true)) {
            return null;
        }

        return $this->doctor->toArray($this->doctor->for($connection, $snapshot));
    }

    /**
     * The structural diff against the recorded baseline, or null when the feature
     * is disabled or no baseline exists. The baseline is exclusion-filtered to
     * match the snapshot, keeping excluded tables out of the diff.
     *
     * @param  array<string, mixed>  $snapshot  the already exclusion-filtered current snapshot
     * @return array<string, mixed>|null
     */
    private function diffFor(string $connection, array $snapshot): ?array
    {
        if (! config('truss.diff.enabled', true)) {
            return null;
        }

        $baseline = $this->baselines->get($connection);

        if ($baseline === null) {
            // A read error and "no baseline recorded yet" both land here, and only
            // the first is worth telling anyone about: the second is the normal
            // state of a fresh install until the next migration.
            $this->baselineUnavailable = $this->baselines->lastError() !== null;

            return null;
        }

        $baseline['tables'] = $this->withoutExcludedTables($baseline['tables'] ?? [], $connection);

        return $this->differ->diff($baseline, $snapshot);
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
