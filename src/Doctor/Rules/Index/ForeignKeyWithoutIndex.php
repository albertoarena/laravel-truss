<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Rules\Index;

use AlbertoArena\Truss\Doctor\Category;
use AlbertoArena\Truss\Doctor\Confidence;
use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\Severity;

/**
 * TRUSS-IDX-001: a foreign key column with no index leading with it, so every
 * join and cascade on it scans the table.
 *
 * Engine aware: MySQL and MariaDB create the index implicitly with the foreign
 * key, so this rarely fires there and is only info; PostgreSQL, SQLite, and
 * others do not, so it is an error. defaultSeverity() is the non-MySQL default.
 */
final class ForeignKeyWithoutIndex implements Rule
{
    public function code(): string
    {
        return 'TRUSS-IDX-001';
    }

    public function category(): Category
    {
        return Category::Index;
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
        return 'Foreign key column without an index';
    }

    public function check(array $snapshot, string $connection): iterable
    {
        $autoIndexed = in_array($snapshot['driver'] ?? '', ['mysql', 'mariadb'], true);
        $severity = $autoIndexed ? Severity::Info : Severity::Error;

        foreach ($snapshot['tables'] ?? [] as $table) {
            foreach ($table['foreign_keys'] ?? [] as $foreignKey) {
                $columns = $foreignKey['columns'];

                if ($this->coveredByLeadingIndex($table, $columns)) {
                    continue;
                }

                $label = implode(', ', $columns);

                yield new Finding(
                    code: $this->code(),
                    severity: $severity,
                    connection: $connection,
                    table: $table['name'],
                    column: $label,
                    message: "Foreign key column \"{$table['name']}.{$label}\" has no index, so joins and cascades scan the table.",
                    hint: $autoIndexed
                        ? 'MySQL usually indexes foreign keys automatically, so an unindexed one is unexpected; add an index to be safe.'
                        : 'This engine does not index foreign keys automatically. Add an index on the column so lookups and cascades stay fast.',
                    suggestion: null,
                );
            }
        }
    }

    /**
     * Whether an index or the primary key leads with exactly the given columns.
     *
     * @param  array<string, mixed>  $table
     * @param  list<string>  $columns
     */
    private function coveredByLeadingIndex(array $table, array $columns): bool
    {
        $count = count($columns);

        if (array_slice($table['primary_key'] ?? [], 0, $count) === $columns) {
            return true;
        }

        foreach ($table['indexes'] ?? [] as $index) {
            if (array_slice($index['columns'] ?? [], 0, $count) === $columns) {
                return true;
            }
        }

        return false;
    }
}
