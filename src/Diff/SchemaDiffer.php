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
     * `$restrictIsNoAction` reads a foreign key's `restrict` and `no action` as
     * one action. It is true only when both snapshots come from MySQL or
     * MariaDB, which is forDrivers()'s decision to make.
     */
    public function __construct(private readonly bool $restrictIsNoAction = false) {}

    /**
     * A differ for two snapshots from these drivers, current first.
     *
     * A foreign key with no ON UPDATE or ON DELETE clause, which is what
     * Laravel's foreign() writes, reads back as `no action` on MySQL and as
     * `restrict` on MariaDB. On those servers the two are one check, made at
     * once. On PostgreSQL and SQLite they are not, because `no action` can be
     * deferred to the end of the transaction, and a snapshot does not record
     * which server produced it. So the equivalence is decided here, by a caller
     * that knows both drivers, and never assumed.
     */
    public static function forDrivers(string $current, string $baseline): self
    {
        $mysqlFamily = ['mysql', 'mariadb'];

        return new self(in_array($current, $mysqlFamily, true) && in_array($baseline, $mysqlFamily, true));
    }

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
            [
                'on_update' => $this->comparableAction(...),
                'on_delete' => $this->comparableAction(...),
            ],
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
        return $this->diffKeyed($baseline, $current, ['type', 'nullable', 'default'], [
            'type' => $this->comparableType(...),
            'default' => $this->comparableDefault(...),
        ]);
    }

    /**
     * A foreign key action, with `restrict` read as `no action` when both
     * sides are servers on which they are the same check.
     */
    private function comparableAction(mixed $action): mixed
    {
        if (! is_string($action)) {
            return $action;
        }

        $action = strtolower($action);

        return $this->restrictIsNoAction && $action === 'restrict' ? 'no action' : $action;
    }

    /**
     * The type as both MySQL and MariaDB would agree on it. MariaDB prints the
     * integer display width MySQL 8 dropped (`int(11)` against `int`), and the
     * width never changed what a column holds. Two widths do carry meaning and
     * are kept: `tinyint(1)`, which both servers and Laravel read as boolean,
     * and any width on a zerofill column, which decides the padding.
     */
    private function comparableType(mixed $type): mixed
    {
        if (! is_string($type)) {
            return $type;
        }

        $type = strtolower($type);

        if ($type === 'tinyint(1)' || str_contains($type, 'zerofill')) {
            return $type;
        }

        return (string) preg_replace('/^(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/', '$1', $type);
    }

    /**
     * The default as both servers would agree on it. MariaDB writes a string
     * default as an SQL literal (`'publish'`, with `''` for a quote inside),
     * writes no default as the bare text NULL, and spells the current time as
     * a call, `current_timestamp()`, where MySQL writes `CURRENT_TIMESTAMP`.
     * A quoted `'NULL'` is a string default and stays one.
     */
    private function comparableDefault(mixed $default): mixed
    {
        if (! is_string($default)) {
            return $default;
        }

        if (strcasecmp($default, 'NULL') === 0) {
            return null;
        }

        if (preg_match("/^'((?:[^']|'')*)'$/s", $default, $literal) === 1) {
            return str_replace("''", "'", $literal[1]);
        }

        if (preg_match('/^current_timestamp(?:\((\d*)\))?$/i', $default, $call) === 1) {
            return ($call[1] ?? '') === '' ? 'current_timestamp' : 'current_timestamp('.$call[1].')';
        }

        return $default;
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
     * @param  array<string, callable(mixed): mixed>  $comparable  how to read each
     *                                                             field before comparing it
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function diffKeyed(array $baseline, array $current, array $fields, array $comparable = []): array
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

            $fieldChanges = $this->fieldChanges($baselineByName[$name], $item, $fields, $comparable);

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
     * @param  array<string, callable(mixed): mixed>  $comparable
     * @return array<string, array{before: mixed, after: mixed}>
     */
    private function fieldChanges(array $before, array $after, array $fields, array $comparable): array
    {
        $changes = [];
        foreach ($fields as $field) {
            $read = $comparable[$field] ?? fn (mixed $value): mixed => $value;

            if ($read($before[$field] ?? null) !== $read($after[$field] ?? null)) {
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
