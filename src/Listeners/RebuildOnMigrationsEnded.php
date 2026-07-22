<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Listeners;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use Illuminate\Database\Events\MigrationsEnded;

/**
 * Keeps the cached snapshot fresh by rebuilding after migrations run
 * (migrate, migrate:rollback, migrate:fresh all fire MigrationsEnded).
 *
 * Only rebuilds when Truss is enabled — the manual truss:rebuild command is the
 * escape hatch that always works, regardless of this switch.
 */
class RebuildOnMigrationsEnded
{
    public function __construct(
        private readonly SchemaCacheRepository $cache,
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

        foreach ($connections as $name) {
            $this->cache->rebuild($name);
        }
    }
}
