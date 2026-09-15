<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Commands;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Commands\Concerns\WarnsWhenUncached;
use Illuminate\Console\Command;

/**
 * Print the database structure as a terminal table: the text counterpart to the
 * visual dashboard, and the closest thing to the `schema:show` Laravel does not
 * ship. Structure only (table, column count, foreign-key count), never row data,
 * and it applies the same `excluded_tables` filter the diagram does, over the
 * same cached snapshot.
 */
class ShowCommand extends Command
{
    use WarnsWhenUncached;

    protected $signature = 'truss:show {--connection= : Show this connection instead of the default}';

    protected $description = 'Print the database structure as a table';

    public function handle(SchemaCacheRepository $cache): int
    {
        $connection = $this->option('connection') ? (string) $this->option('connection') : null;
        $snapshot = $cache->get($connection);
        $this->warnIfUncached($cache);
        $connection = $snapshot['connection'];

        // The cached snapshot is deliberately unfiltered, so that changing the
        // exclusion list needs no rebuild. Filtering is the caller's job, and
        // this caller skipped it for a long time while its docblock said it did
        // not: the diagram hid a table and the terminal printed it.
        $known = $snapshot['tables'] ?? [];
        $tables = $this->withoutExcludedTables($known, $connection);

        if ($tables === []) {
            $this->warn($known === []
                ? "No tables found for connection [{$connection}]."
                : "Every table on connection [{$connection}] is excluded by config.");

            return self::SUCCESS;
        }

        $rows = array_map(static fn (array $table): array => [
            $table['name'],
            (string) count($table['columns'] ?? []),
            (string) count($table['foreign_keys'] ?? []),
        ], $tables);

        $this->table(['Table', 'Columns', 'Foreign keys'], $rows);

        $fallback = ($snapshot['fallback'] ?? false) ? ' <comment>(SQLite fallback)</comment>' : '';
        $this->line($this->scope(count($tables), count($known)).' on <info>'.$connection.'</info>'.$fallback.'.');
        $this->line('See the diagram with <info>php artisan truss:open</info>.');

        return self::SUCCESS;
    }

    /**
     * How much of the connection is on screen: "2 tables" when that is all of
     * them, "2 of 10 tables" when config hid the rest.
     *
     * Phrased as scope rather than as concealment, like the dashboard footer. It
     * says Truss saw ten and is printing two, which is the question a reader has
     * when the list looks short, and it never names what was left out.
     */
    private function scope(int $shown, int $known): string
    {
        if ($shown === $known) {
            return '<info>'.$shown.'</info> '.($shown === 1 ? 'table' : 'tables');
        }

        return '<info>'.$shown.'</info> of <info>'.$known.'</info> '.($known === 1 ? 'table' : 'tables');
    }

    /**
     * Drop any table excluded globally or for this connection.
     *
     * @param  list<array<string, mixed>>  $tables
     * @return list<array<string, mixed>>
     */
    private function withoutExcludedTables(array $tables, string $connection): array
    {
        $excluded = [
            ...(array) config('truss.excluded_tables', []),
            ...(array) config("truss.connections.{$connection}.excluded_tables", []),
        ];

        return array_values(array_filter(
            $tables,
            fn (array $table): bool => ! in_array($table['name'], $excluded, true),
        ));
    }
}
