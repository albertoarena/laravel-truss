<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Commands;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Diff\BaselineStore;
use AlbertoArena\Truss\Diff\SchemaDiffer;
use Illuminate\Console\Command;

/**
 * Print "what changed since the last migration" as a terminal diff: the text
 * counterpart to the dashboard "Changes" panel. Compares the recorded baseline
 * (the schema before the last migration) against the current cached snapshot.
 * Structure only, never row data, so it is safe in CI and commit hooks.
 */
class DiffCommand extends Command
{
    protected $signature = 'truss:diff {--connection= : Diff this connection instead of the default}';

    protected $description = 'Show what changed in the database structure since the last migration';

    public function handle(SchemaCacheRepository $cache, BaselineStore $baselines, SchemaDiffer $differ): int
    {
        if (! config('truss.diff.enabled', true)) {
            $this->warn('Schema diff is disabled. Set truss.diff.enabled to true to record a baseline.');

            return self::SUCCESS;
        }

        $connection = $this->option('connection') ? (string) $this->option('connection') : null;
        $current = $cache->get($connection);
        $name = $current['connection'];

        $baseline = $baselines->get($name);

        if ($baseline === null) {
            // A read failure and an absent baseline both arrive as null, and they
            // need different advice: one is the normal state of a fresh install,
            // the other is a misconfigured disk the user cannot guess at from
            // "no baseline recorded".
            $error = $baselines->lastError();

            if ($error !== null) {
                $disk = (string) config('truss.diff.disk', 'local');
                $this->warn("Could not read the diff baseline from the [{$disk}] disk.");
                $this->line("  {$error}");
                $this->line('Set TRUSS_DIFF_DISK to a local disk, or disable the feature with TRUSS_DIFF_ENABLED=false.');
                $this->line('See https://trussphp.com/guides/schema-diff/');

                return self::SUCCESS;
            }

            $this->warn("No baseline recorded for [{$name}] yet.");
            $this->line('A baseline is captured on the next migration while Truss is enabled.');

            return self::SUCCESS;
        }

        $diff = $differ->diff($baseline, $current);

        if (! $diff['has_changes']) {
            $this->info("No structural changes on [{$name}] since the last migration.");

            return self::SUCCESS;
        }

        $this->line("Schema changes on <info>{$name}</info> since the last migration:");

        $this->printTableList('Added tables', '+', $diff['tables_added']);
        $this->printTableList('Removed tables', '-', $diff['tables_removed']);
        $this->printChangedTables($diff['tables_changed']);

        return self::SUCCESS;
    }

    /**
     * @param  list<array{name: string}>  $tables
     */
    private function printTableList(string $heading, string $marker, array $tables): void
    {
        if ($tables === []) {
            return;
        }

        $this->newLine();
        $this->line("<comment>{$heading}:</comment>");

        foreach ($tables as $table) {
            $this->line("  {$marker} {$table['name']}");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $tables
     */
    private function printChangedTables(array $tables): void
    {
        if ($tables === []) {
            return;
        }

        $this->newLine();
        $this->line('<comment>Changed tables:</comment>');

        foreach ($tables as $table) {
            $this->line("  ~ {$table['name']}");

            foreach ($this->describeTableChanges($table) as $line) {
                $this->line("      {$line}");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $table
     * @return list<string>
     */
    private function describeTableChanges(array $table): array
    {
        $lines = [];

        foreach ($table['columns_added'] as $column) {
            $lines[] = "column added: {$column['name']} ({$column['type']})";
        }
        foreach ($table['columns_removed'] as $column) {
            $lines[] = "column removed: {$column['name']}";
        }
        foreach ($table['columns_changed'] as $column) {
            $lines[] = "column changed: {$column['name']} (".$this->describeChanges($column['changes']).')';
        }

        foreach ($table['indexes_added'] as $index) {
            $lines[] = "index added: {$index['name']}";
        }
        foreach ($table['indexes_removed'] as $index) {
            $lines[] = "index removed: {$index['name']}";
        }
        foreach ($table['indexes_changed'] as $index) {
            $lines[] = "index changed: {$index['name']}";
        }

        foreach ($table['foreign_keys_added'] as $fk) {
            $lines[] = "foreign key added: {$fk['name']}";
        }
        foreach ($table['foreign_keys_removed'] as $fk) {
            $lines[] = "foreign key removed: {$fk['name']}";
        }
        foreach ($table['foreign_keys_changed'] as $fk) {
            $lines[] = "foreign key changed: {$fk['name']}";
        }

        if (isset($table['changes']['primary_key'])) {
            $pk = $table['changes']['primary_key'];
            $lines[] = 'primary key: ['.implode(', ', $pk['before']).'] -> ['.implode(', ', $pk['after']).']';
        }

        return $lines;
    }

    /**
     * Render a per-field before/after change map as "field: before -> after".
     *
     * @param  array<string, array{before: mixed, after: mixed}>  $changes
     */
    private function describeChanges(array $changes): string
    {
        $parts = [];
        foreach ($changes as $field => $change) {
            $parts[] = "{$field}: ".$this->scalar($change['before']).' -> '.$this->scalar($change['after']);
        }

        return implode(', ', $parts);
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_array($value) => '['.implode(', ', $value).']',
            default => (string) $value,
        };
    }
}
