<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Diff;

/**
 * Compares two serialized schema snapshots (the shape SchemaSerializer produces,
 * wrapped in the cache envelope) and returns a plain diff array describing what
 * changed: tables, columns, indexes, foreign keys, and primary keys added,
 * removed, or changed.
 *
 * Pure and deterministic: no database, no framework, no I/O. Tables, columns,
 * indexes and foreign keys are matched by name, so a rename reads as a remove
 * plus an add (a documented limitation). Structure only, both inputs are already
 * structure-only, so the diff cannot expose row data by construction.
 */
class SchemaDiffer
{
    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    public function diff(array $baseline, array $current): array
    {
        $baselineTables = $this->byName($baseline['tables'] ?? []);
        $currentTables = $this->byName($current['tables'] ?? []);

        $tablesAdded = [];
        foreach ($currentTables as $name => $_table) {
            if (! isset($baselineTables[$name])) {
                $tablesAdded[] = ['name' => $name];
            }
        }

        $tablesRemoved = [];
        foreach ($baselineTables as $name => $_table) {
            if (! isset($currentTables[$name])) {
                $tablesRemoved[] = ['name' => $name];
            }
        }

        $tablesChanged = [];
        foreach ($currentTables as $name => $currentTable) {
            if (! isset($baselineTables[$name])) {
                continue;
            }

            $tableDiff = $this->diffTable($baselineTables[$name], $currentTable);

            if ($tableDiff !== null) {
                $tablesChanged[] = $tableDiff;
            }
        }

        $hasChanges = $tablesAdded !== [] || $tablesRemoved !== [] || $tablesChanged !== [];

        return [
            'tables_added' => $tablesAdded,
            'tables_removed' => $tablesRemoved,
            'tables_changed' => $tablesChanged,
            'has_changes' => $hasChanges,
            'baseline_generated_at' => $baseline['generated_at'] ?? null,
            'current_generated_at' => $current['generated_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>|null null when the table is unchanged
     */
    private function diffTable(array $baseline, array $current): ?array
    {
        [$columnsAdded, $columnsRemoved, $columnsChanged] = $this->diffColumns(
            $baseline['columns'] ?? [],
            $current['columns'] ?? [],
        );

        [$indexesAdded, $indexesRemoved, $indexesChanged] = $this->diffKeyed(
            $baseline['indexes'] ?? [],
            $current['indexes'] ?? [],
            ['columns', 'unique'],
        );

        [$foreignKeysAdded, $foreignKeysRemoved, $foreignKeysChanged] = $this->diffKeyed(
            $baseline['foreign_keys'] ?? [],
            $current['foreign_keys'] ?? [],
            ['columns', 'references_table', 'references_columns', 'on_update', 'on_delete'],
        );

        $changes = [];
        if (($baseline['primary_key'] ?? []) !== ($current['primary_key'] ?? [])) {
            $changes['primary_key'] = [
                'before' => $baseline['primary_key'] ?? [],
                'after' => $current['primary_key'] ?? [],
            ];
        }

        $anyChange = $columnsAdded || $columnsRemoved || $columnsChanged
            || $indexesAdded || $indexesRemoved || $indexesChanged
            || $foreignKeysAdded || $foreignKeysRemoved || $foreignKeysChanged
            || $changes !== [];

        if (! $anyChange) {
            return null;
        }

        return [
            'name' => $current['name'],
            'columns_added' => $columnsAdded,
            'columns_removed' => $columnsRemoved,
            'columns_changed' => $columnsChanged,
            'indexes_added' => $indexesAdded,
            'indexes_removed' => $indexesRemoved,
            'indexes_changed' => $indexesChanged,
            'foreign_keys_added' => $foreignKeysAdded,
            'foreign_keys_removed' => $foreignKeysRemoved,
            'foreign_keys_changed' => $foreignKeysChanged,
            'changes' => $changes,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $baseline
     * @param  list<array<string, mixed>>  $current
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function diffColumns(array $baseline, array $current): array
    {
        return $this->diffKeyed($baseline, $current, ['type', 'nullable', 'default']);
    }

    /**
     * Diff two name-keyed lists of associative arrays. Items are matched by their
     * `name`; `changes` on a matched item covers only the compared fields that
     * differ. Added items carry their full definition; removed items carry just
     * their name.
     *
     * @param  list<array<string, mixed>>  $baseline
     * @param  list<array<string, mixed>>  $current
     * @param  list<string>  $fields  the fields whose difference marks a change
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function diffKeyed(array $baseline, array $current, array $fields): array
    {
        $baselineByName = $this->byName($baseline);
        $currentByName = $this->byName($current);

        $added = [];
        $changed = [];
        foreach ($currentByName as $name => $item) {
            if (! isset($baselineByName[$name])) {
                $added[] = $item;

                continue;
            }

            $fieldChanges = $this->fieldChanges($baselineByName[$name], $item, $fields);

            if ($fieldChanges !== []) {
                $changed[] = ['name' => $name, 'changes' => $fieldChanges];
            }
        }

        $removed = [];
        foreach ($baselineByName as $name => $_item) {
            if (! isset($currentByName[$name])) {
                $removed[] = ['name' => $name];
            }
        }

        return [$added, $removed, $changed];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>  $fields
     * @return array<string, array{before: mixed, after: mixed}>
     */
    private function fieldChanges(array $before, array $after, array $fields): array
    {
        $changes = [];
        foreach ($fields as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changes[$field] = ['before' => $before[$field] ?? null, 'after' => $after[$field] ?? null];
            }
        }

        return $changes;
    }

    /**
     * Index a list of items by their `name`, preserving input order.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, array<string, mixed>>
     */
    private function byName(array $items): array
    {
        $keyed = [];
        foreach ($items as $item) {
            $keyed[$item['name']] = $item;
        }

        return $keyed;
    }
}
