<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Commands;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use Illuminate\Console\Command;

/**
 * Manually rebuild the cached schema snapshot — for CI, seeding workflows, or
 * forcing a refresh. Works regardless of the truss.enabled switch.
 */
class RebuildCommand extends Command
{
    protected $signature = 'truss:rebuild {--connection= : Rebuild only this connection instead of all managed ones}';

    protected $description = 'Rebuild the cached Truss schema snapshot';

    public function handle(SchemaCacheRepository $cache): int
    {
        $connections = $this->option('connection')
            ? [(string) $this->option('connection')]
            : $cache->managedConnections();

        foreach ($connections as $connection) {
            $snapshot = $cache->rebuild($connection);
            $note = $snapshot['fallback'] ? ' <comment>(SQLite fallback)</comment>' : '';
            $this->line("Rebuilt schema snapshot for <info>{$connection}</info>{$note}.");
        }

        return self::SUCCESS;
    }
}
