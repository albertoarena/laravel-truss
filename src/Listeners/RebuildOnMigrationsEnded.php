<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Listeners;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Diff\BaselineStore;
use Illuminate\Database\Events\MigrationsEnded;

/**
 * Keeps the cached snapshot fresh by rebuilding after migrations run
 * (migrate, migrate:rollback, migrate:fresh all fire MigrationsEnded).
 *
 * Only rebuilds when Truss is enabled — the manual truss:rebuild command is the
 * escape hatch that always works, regardless of this switch.
 *
 * This is also the one place that knows a migration just ran, so it is where the
 * schema-diff baseline is captured: before overwriting the cache, the snapshot
 * that is currently cached (the pre-migration schema) is saved as the baseline.
 * The event fires after the migration, so the cache is the only remaining source
 * of the previous schema. Capture is skipped when the feature is disabled, when
 * nothing is cached yet, so no file is ever written in those cases.
 */
class RebuildOnMigrationsEnded
{
    public function __construct(
        private readonly SchemaCacheRepository $cache,
        private readonly BaselineStore $baselines = new BaselineStore,
    ) {}

    public function handle(MigrationsEnded $event): void
    {
        if (! config('truss.enabled')) {
            return;
        }

        $connection = $event->options['database'] ?? null;

        $connections = $connection !== null
            ? [(string) $connection]
            : $this->cache->managedConnections();

        $captureBaseline = (bool) config('truss.diff.enabled', true);

        foreach ($connections as $name) {
            if ($captureBaseline) {
                $previous = $this->cache->peek($name);

                if ($previous !== null) {
                    $this->baselines->save($name, $previous);
                }
            }

            $this->cache->rebuild($name);
        }
    }
}
