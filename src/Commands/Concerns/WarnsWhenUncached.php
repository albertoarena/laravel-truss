<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Commands\Concerns;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;

/**
 * Shared notice for the read-only commands (truss:show, truss:doctor,
 * truss:diff) when the schema had to be read live because the cache store was
 * not usable.
 *
 * They all still succeed: the snapshot is complete, it was just built rather than
 * cached. Silence would be wrong, though, since the next run pays the same cost
 * and the user has no other hint that their cache store is broken.
 */
trait WarnsWhenUncached
{
    protected function warnIfUncached(SchemaCacheRepository $cache): void
    {
        $error = $cache->lastError();

        if ($error === null) {
            return;
        }

        $this->warn("The cache store is unavailable, so the structure was read live: {$error}");
        $this->line('Structure below is complete. Fix the cache store to make this fast again.');
    }
}
