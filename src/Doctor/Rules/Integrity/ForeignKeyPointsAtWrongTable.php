<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Rules\Integrity;

use AlbertoArena\Truss\Doctor\Category;
use AlbertoArena\Truss\Doctor\Confidence;
use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\Severity;
use Illuminate\Support\Str;

/**
 * TRUSS-INT-004: a single-column `*_id` foreign key that references one table
 * while a table named after the column exists and is a different one. The
 * constraint then validates against the wrong parent, so a valid id is rejected
 * unless it happens to exist in both tables, and `ON DELETE CASCADE` deletes
 * rows when the wrong parent goes.
 *
 * This is the sibling of TRUSS-INT-002. That rule asks why a foreign-key-shaped
 * column has no constraint; this one asks why the constraint it has disagrees
 * with its name.
 *
 * THE WHOLE DIFFICULTY IS THAT MOST NAME MISMATCHES ARE DELIBERATE. Aliases are
 * ordinary and correct: `author_id` referencing `users`, `merged_id` referencing
 * `carts`, `parent_transaction_id` referencing `transactions`. Measured on
 * Lunar 1.5.0, six of the seven mismatches among 83 foreign keys were aliases of
 * that kind, so a rule that flagged every mismatch would have been wrong 86% of
 * the time.
 *
 * So the test is not "the names differ". It is "the name names a table that
 * actually exists, and the key points somewhere else". An alias survives because
 * there is no `authors` or `mergeds` table for its name to name. The rule stays
 * quiet unless exactly one candidate resolves, and a prefixed schema is matched
 * through the prefix of the table the key already references, so `lunar_` tables
 * are neither invisible nor confused with another application's.
 */
final class ForeignKeyPointsAtWrongTable implements Rule
{
    public function code(): string
    {
        return 'TRUSS-INT-004';
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
        return 'Foreign key references a different table from the one it is named after';
    }

    public function check(array $snapshot, string $connection): iterable
    {
        $tables = $snapshot['tables'] ?? [];
        $tableNames = array_column($tables, 'name');

        foreach ($tables as $table) {
            $columnNames = array_column($table['columns'] ?? [], 'name');

            foreach ($table['foreign_keys'] ?? [] as $foreignKey) {
                $columns = $foreignKey['columns'] ?? [];

                // A composite key is not named after one table, so there is no
                // name to test.
                if (count($columns) !== 1) {
                    continue;
                }

                $column = $columns[0];

                if (! str_ends_with($column, '_id')) {
                    continue;
                }

                $base = substr($column, 0, -3);

                if ($base === '') {
                    continue;
                }

                // A morph target points at whichever table its sibling `_type`
                // column names, so its own name is not a claim about one table.
                // TRUSS-INT-009 owns that shape.
                if (in_array($base.'_type', $columnNames, true)) {
                    continue;
                }

                $referenced = $foreignKey['references_table'] ?? null;

                if (! is_string($referenced) || $referenced === '') {
                    continue;
                }

                $named = $this->tableTheNameNames($base, $tableNames, $referenced);

                if ($named === null || $named === $referenced) {
                    continue;
                }

                yield new Finding(
                    code: $this->code(),
                    severity: $this->defaultSeverity(),
                    connection: $connection,
                    table: $table['name'],
                    column: $column,
                    message: "Foreign key \"{$table['name']}.{$column}\" references \"{$referenced}\", but a table named \"{$named}\" exists and is what the column name points to.",
                    hint: 'A foreign key validates against the table it references, so this one rejects a valid id unless the same id also exists in the referenced table, and ON DELETE CASCADE fires when the wrong parent row is deleted. If the reference is a deliberate alias, add it to the doctor ignore list.',
                    suggestion: null,
                );
            }
        }
    }

    /**
     * The table a `*_id` base names, or null when the name names nothing, or
     * names more than one thing.
     *
     * Candidates are tried plural first, then singular, and the first candidate
     * that resolves wins. A candidate resolves only when exactly one table
     * matches it: either the table is called exactly that, or it is called that
     * under a prefix which the referenced table also carries. More than one
     * match is ambiguous, and guessing is worse than silence.
     *
     * @param  list<string>  $tableNames
     */
    private function tableTheNameNames(string $base, array $tableNames, string $referenced): ?string
    {
        $candidates = array_unique([Str::plural($base), $base]);

        foreach ($candidates as $candidate) {
            $matches = [];

            foreach ($tableNames as $name) {
                if ($name === $candidate) {
                    $matches[] = $name;

                    continue;
                }

                if (! str_ends_with($name, '_'.$candidate)) {
                    continue;
                }

                $prefix = substr($name, 0, -strlen($candidate));

                // Same prefix as the table the key already points at, so this
                // is the same application's schema rather than another one
                // that happens to use the word.
                if (str_starts_with($referenced, $prefix)) {
                    $matches[] = $name;
                }
            }

            if (count($matches) === 1) {
                return $matches[0];
            }

            if ($matches !== []) {
                return null;
            }
        }

        return null;
    }
}
