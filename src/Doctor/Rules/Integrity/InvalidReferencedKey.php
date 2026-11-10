<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Rules\Integrity;

use AlbertoArena\Truss\Doctor\Category;
use AlbertoArena\Truss\Doctor\Confidence;
use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\IndexKey;
use AlbertoArena\Truss\Doctor\Severity;

/**
 * TRUSS-INT-008: a foreign key whose referenced columns are neither the primary
 * key nor the key of a unique index. InnoDB, MariaDB and SQLite accept this, so
 * one child row can match several parent rows and the relationship is not the
 * one-to-many it looks like. Compared as a set, so a unique index on (b, a)
 * covers a key on (a, b), and through IndexKey, so a unique expression index
 * never counts for the plain column. Runs on every driver: where the database
 * rejects the DDL (PostgreSQL, MySQL 8.4 by default) it simply finds nothing.
 * Skipped when the referenced table is not in the snapshot (excluded, or
 * another database). A composite key is reported under its constraint name,
 * or its columns where the database reports no name (SQLite).
 */
final class InvalidReferencedKey implements Rule
{
    public function code(): string
    {
        return 'TRUSS-INT-008';
    }

    public function category(): Category
    {
        return Category::Integrity;
    }

    public function confidence(): Confidence
    {
        return Confidence::High;
    }

    public function defaultSeverity(): Severity
    {
        return Severity::Error;
    }

    public function title(): string
    {
        return 'Foreign key references a key that is not unique';
    }

    public function check(array $snapshot, string $connection): iterable
    {
        $byName = [];
        foreach ($snapshot['tables'] ?? [] as $table) {
            $byName[$table['name']] = $table;
        }

        foreach ($byName as $table) {
            foreach ($table['foreign_keys'] ?? [] as $fk) {
                $referenced = $byName[$fk['references_table']] ?? null;

                if ($referenced === null || $this->isUniqueKey($referenced, $fk['references_columns'])) {
                    continue;
                }

                $target = $fk['references_table'].'.'.implode(', ', $fk['references_columns']);
                $single = count($fk['columns']) === 1;
                $label = ($fk['name'] ?? '') !== '' ? $fk['name'] : implode(', ', $fk['columns']);

                yield new Finding(
                    code: $this->code(),
                    severity: $this->defaultSeverity(),
                    connection: $connection,
                    table: $table['name'],
                    column: $single ? $fk['columns'][0] : $label,
                    message: $single
                        ? "Foreign key \"{$table['name']}.{$fk['columns'][0]}\" references \"{$target}\", which is not a primary key or unique."
                        : "Foreign key on ({$label}) in \"{$table['name']}\" references \"{$target}\", which is not a primary key or unique together.",
                    hint: 'A foreign key should reference a primary key or a unique key. Otherwise one row can match several parent rows, and the relationship is not the one-to-many it appears to be.',
                    suggestion: null,
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $table
     * @param  list<string>  $columns
     */
    private function isUniqueKey(array $table, array $columns): bool
    {
        if ($this->sameSet($table['primary_key'] ?? [], $columns)) {
            return true;
        }

        foreach ($table['indexes'] ?? [] as $index) {
            if (($index['unique'] ?? false) && $this->sameSet(IndexKey::of($index), $columns)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function sameSet(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a !== [] && $a === $b;
    }
}
