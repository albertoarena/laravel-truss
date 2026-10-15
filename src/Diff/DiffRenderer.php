<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Diff;

/**
 * A structural diff rendered as lines of text.
 *
 * It exists because there are two diffs now. `truss:diff` compares the current
 * schema against the baseline recorded before the last migration; the binary's
 * `truss diff` compares two live databases, which is the thing the package
 * cannot do. The question differs, so the sentence above the report differs and
 * each command writes its own. **The body does not differ, and a second copy of
 * this formatting is exactly the drift the parity tests exist to catch.**
 *
 * Pure: a diff array in, lines out. It returns lines rather than writing them,
 * so a caller can print them, buffer them or assert on them, and so nothing
 * here needs an output, a container or a connection name.
 *
 * The markup tags are Symfony Console's, which Laravel's console is built on,
 * so both surfaces style them identically and neither has to strip them.
 */
final class DiffRenderer
{
    /**
     * Everything below the headline. Empty when nothing changed, because the
     * caller owns that sentence too.
     *
     * @param  array<string, mixed>  $diff  as SchemaDiffer::diff() returns it
     * @return list<string>
     */
    public static function body(array $diff): array
    {
        if (($diff['has_changes'] ?? false) === false) {
            return [];
        }

        return [
            ...self::tableList('Added tables', '+', $diff['tables_added'] ?? []),
            ...self::tableList('Removed tables', '-', $diff['tables_removed'] ?? []),
            ...self::changedTables($diff['tables_changed'] ?? []),
        ];
    }

    /**
     * @param  list<array{name: string}>  $tables
     * @return list<string>
     */
    private static function tableList(string $heading, string $marker, array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        $lines = ['', "<comment>{$heading}:</comment>"];

        foreach ($tables as $table) {
            $lines[] = "  {$marker} {$table['name']}";
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $tables
     * @return list<string>
     */
    private static function changedTables(array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        $lines = ['', '<comment>Changed tables:</comment>'];

        foreach ($tables as $table) {
            $lines[] = "  ~ {$table['name']}";

            foreach (self::tableChanges($table) as $line) {
                $lines[] = "      {$line}";
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $table
     * @return list<string>
     */
    private static function tableChanges(array $table): array
    {
        $lines = [];

        foreach ($table['columns_added'] as $column) {
            $lines[] = "column added: {$column['name']} ({$column['type']})";
        }
        foreach ($table['columns_removed'] as $column) {
            $lines[] = "column removed: {$column['name']}";
        }
        foreach ($table['columns_changed'] as $column) {
            $lines[] = "column changed: {$column['name']} (".self::changes($column['changes']).')';
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
     * A per-field before and after map as "field: before -> after".
     *
     * @param  array<string, array{before: mixed, after: mixed}>  $changes
     */
    private static function changes(array $changes): string
    {
        $parts = [];

        foreach ($changes as $field => $change) {
            $parts[] = "{$field}: ".self::scalar($change['before']).' -> '.self::scalar($change['after']);
        }

        return implode(', ', $parts);
    }

    /**
     * Null, booleans and lists each get a word rather than the empty string
     * PHP would otherwise cast them to. A default moving from null to a value
     * is the most common change there is, and "default:  -> 0" says nothing.
     */
    private static function scalar(mixed $value): string
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
