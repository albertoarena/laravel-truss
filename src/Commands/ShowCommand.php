<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Commands;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use Illuminate\Console\Command;

/**
 * Print the database structure as a terminal table: the text counterpart to the
 * visual dashboard, and the closest thing to the `schema:show` Laravel does not
 * ship. Structure only (table, column count, foreign-key count), never row data,
 * and it reads the same cached, exclusion-filtered snapshot the diagram does.
 */
class ShowCommand extends Command
{
    protected $signature = 'truss:show {--connection= : Show this connection instead of the default}';

    protected $description = 'Print the database structure as a table';

    public function handle(SchemaCacheRepository $cache): int
    {
        $connection = $this->option('connection') ? (string) $this->option('connection') : null;
        $snapshot = $cache->get($connection);
        $tables = $snapshot['tables'] ?? [];

        if ($tables === []) {
            $this->warn("No tables found for connection [{$snapshot['connection']}].");

            return self::SUCCESS;
        }

        $rows = array_map(static fn (array $table): array => [
            $table['name'],
            (string) count($table['columns'] ?? []),
            (string) count($table['foreign_keys'] ?? []),
        ], $tables);

        $this->table(['Table', 'Columns', 'Foreign keys'], $rows);

        $fallback = ($snapshot['fallback'] ?? false) ? ' <comment>(SQLite fallback)</comment>' : '';
        $this->line('<info>'.count($tables).'</info> tables on <info>'.$snapshot['connection'].'</info>'.$fallback.'.');
        $this->line('See the diagram with <info>php artisan truss:open</info>.');

        return self::SUCCESS;
    }
}
