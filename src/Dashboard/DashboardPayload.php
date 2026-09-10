<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Dashboard;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Diff\BaselineStore;
use AlbertoArena\Truss\Diff\SchemaDiffer;
use AlbertoArena\Truss\Doctor\DoctorReport;
use InvalidArgumentException;

/**
 * Assembles the payload the dashboard runs on: the cached snapshot for a
 * connection, exclusion-filtered, with the structural diff and the doctor
 * findings embedded, plus the two flags that say a subsystem was unavailable.
 *
 * There is one producer of this shape on purpose. `SchemaApiController` serves
 * it over HTTP and `Truss::payload()` returns it in-process, and both call here,
 * so a caller inside the application never has to make an HTTP request to itself
 * and can never receive a second, subtly different assembly. The equality is
 * pinned by tests/Feature/Dashboard/DashboardPayloadTest.php.
 *
 * Invariants this class owns, all of them previously enforced in the controller:
 *
 *   - Config `excluded_tables` (global + per-connection) are filtered out at
 *     serve time from the full cached snapshot, so excluded structure never
 *     reaches a caller and toggling exclusions needs no rebuild. This is the
 *     server-side half of the "no data exposed" promise, and it is applied
 *     before the diff and the doctor run, so an excluded table cannot surface
 *     through either of them.
 *   - `diff` keeps its null-or-object shape; `diff_unavailable` is signalled
 *     beside it, and only when the baseline could not be read (a disk problem,
 *     never "no baseline recorded yet").
 *   - `cache_unavailable` is present only when the snapshot had to be built
 *     live. The structure is complete either way, so it is a notice about
 *     speed, never a partial payload.
 *
 * Structure only, like everything else Truss touches: never row contents.
 */
final class DashboardPayload
{
    public function __construct(
        private readonly SchemaCacheRepository $cache,
        private readonly BaselineStore $baselines = new BaselineStore,
        private readonly SchemaDiffer $differ = new SchemaDiffer,
        private readonly DoctorReport $doctor = new DoctorReport,
    ) {}

    /**
     * The payload for a managed connection, defaulting to the app's own.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when the connection is not one Truss manages
     */
    public function for(?string $connection = null): array
    {
        $connection = $this->resolveConnection($connection);

        $snapshot = $this->cache->get($connection);

        // A snapshot Truss could not cache is still a correct snapshot: it was
        // just built live, which is slower. Flagged rather than throwing, so the
        // diagram works on a broken cache store and the caller can say why.
        $cacheUnavailable = $this->cache->lastError() !== null;

        $snapshot['tables'] = $this->withoutExcludedTables($snapshot['tables'], $connection);

        [$diff, $baselineUnavailable] = $this->diffFor($connection, $snapshot);

        $snapshot['diff'] = $diff;
        $snapshot['doctor'] = $this->doctorFor($connection, $snapshot);

        // Both flags are present only when the thing they describe happened, so
        // an existing client needs no change to keep working.
        if ($baselineUnavailable) {
            $snapshot['diff_unavailable'] = true;
        }

        if ($cacheUnavailable) {
            $snapshot['cache_unavailable'] = true;
        }

        return $snapshot;
    }

    /**
     * The requested connection, or the app default, checked against the managed
     * list.
     *
     * The HTTP route answers an unmanaged connection with a 404, which is not
     * available here, so this refuses loudly instead. Asking a plugin's page or
     * a command for a connection Truss does not manage is a programming error,
     * and returning an empty payload would hide it.
     */
    private function resolveConnection(?string $connection): string
    {
        $connection = $connection !== null && $connection !== ''
            ? $connection
            : (string) config('database.default');

        if (! in_array($connection, $this->cache->managedConnections(), true)) {
            throw new InvalidArgumentException(
                "Truss does not manage the [{$connection}] connection, so it cannot be visualized. ".
                'Check truss.connections.'
            );
        }

        return $connection;
    }

    /**
     * The doctor report for the connection, or null when the dashboard panel is
     * switched off. The snapshot is already exclusion-filtered, so excluded
     * tables never reach a finding.
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
     * The structural diff against the recorded baseline, and whether the
     * baseline could not be read. Null diff when the feature is disabled, when
     * no baseline exists, or when reading one failed; only the last of those
     * sets the flag, because the other two are ordinary states.
     *
     * Returned as a pair rather than recorded on the instance: this class is
     * resolved as a singleton and answers repeated calls, so a flag left on
     * `$this` by one call would leak into the next.
     *
     * @param  array<string, mixed>  $snapshot  the already exclusion-filtered current snapshot
     * @return array{0: array<string, mixed>|null, 1: bool}
     */
    private function diffFor(string $connection, array $snapshot): array
    {
        if (! config('truss.diff.enabled', true)) {
            return [null, false];
        }

        $baseline = $this->baselines->get($connection);

        if ($baseline === null) {
            // A read error and "no baseline recorded yet" both land here, and only
            // the first is worth telling anyone about: the second is the normal
            // state of a fresh install until the next migration.
            return [null, $this->baselines->lastError() !== null];
        }

        $baseline['tables'] = $this->withoutExcludedTables($baseline['tables'] ?? [], $connection);

        return [$this->differ->diff($baseline, $snapshot), false];
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
