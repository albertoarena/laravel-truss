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

        $failed = false;

        foreach ($connections as $connection) {
            $snapshot = $cache->rebuild($connection);
            $error = $cache->lastError();

            // Everywhere else a broken cache store degrades quietly, but this
            // command exists to write the snapshot. Saying "rebuilt" when nothing
            // was stored would hide the one problem the user ran it to fix.
            if ($error !== null) {
                $this->error("Built the schema snapshot for [{$connection}] but could not cache it: {$error}");
                $failed = true;

                continue;
            }

            $note = $snapshot['fallback'] ? ' <comment>(SQLite fallback)</comment>' : '';
            $this->line("Rebuilt schema snapshot for <info>{$connection}</info>{$note}.");
        }

        if ($failed) {
            $this->line('Check your cache store (CACHE_STORE), then run this again.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
