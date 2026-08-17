<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Listeners;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Diff\BaselineStore;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Log;

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
 *
 * This listener never throws. See refresh() for why that is an invariant rather
 * than defensive coding.
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
            $this->refresh($name, $captureBaseline);
        }
    }

    /**
     * Refresh one connection, never throwing.
     *
     * This method is the whole reason the listener is written this way. It runs
     * after the migration has already committed, and Laravel does not swallow
     * listener exceptions, so anything escaping here turns a successful
     * `php artisan migrate` into a failed Artisan command, and can break a deploy
     * step running `migrate --force`. A stale snapshot is recoverable with
     * `truss:rebuild`; a migration reported as failed is not.
     *
     * The cache layer already degrades on its own, so this catch is the backstop
     * for everything else that could go wrong while introspecting a schema Truss
     * has just been told changed.
     */
    private function refresh(string $connection, bool $captureBaseline): void
    {
        try {
            if ($captureBaseline) {
                $previous = $this->cache->peek($connection);

                if ($previous !== null) {
                    $this->baselines->save($connection, $previous);
                }
            }

            $this->cache->rebuild($connection);

            $error = $this->cache->lastError();

            if ($error !== null) {
                Log::warning(
                    "Truss rebuilt the schema snapshot for [{$connection}] but could not cache it: {$error}. "
                    .'The dashboard will read the schema live until the cache store is usable again.'
                );
            }
        } catch (\Throwable $e) {
            Log::warning(
                "Truss could not refresh the schema snapshot for [{$connection}] after the migration: "
                .$e->getMessage().'. Run `php artisan truss:rebuild` once the cause is fixed.'
            );
        }
    }
}
