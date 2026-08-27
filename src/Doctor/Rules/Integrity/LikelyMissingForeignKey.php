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
 * TRUSS-INT-002: a `*_id` column whose name matches an existing table (plural or
 * singular) but which has no foreign key constraint. Heuristic: it is a strong
 * hint of a missing constraint, but a column can legitimately reference a table
 * in another database, so it stays off the recommended preset and is ignorable.
 */
final class LikelyMissingForeignKey implements Rule
{
    public function code(): string
    {
        return 'TRUSS-INT-002';
    }

    public function category(): Category
    {
        return Category::Integrity;
    }

    public function confidence(): Confidence
    {
        return Confidence::Heuristic;
    }

    public function defaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function title(): string
    {
        return 'Foreign-key-shaped column without a constraint';
    }

    public function check(array $snapshot, string $connection): iterable
    {
        $tables = $snapshot['tables'] ?? [];
        $tableNames = array_column($tables, 'name');

        foreach ($tables as $table) {
            $constrained = $this->constrainedColumns($table);
            $columnNames = array_column($table['columns'] ?? [], 'name');

            foreach ($table['columns'] ?? [] as $column) {
                $name = $column['name'];

                if (! str_ends_with($name, '_id') || in_array($name, $constrained, true)) {
                    continue;
                }

                $base = substr($name, 0, -3);

                // A morph target cannot carry a foreign key at all: the value
                // points at whichever table the sibling `_type` column names.
                // This rule used to ask for one anyway, contradicting
                // TRUSS-INT-009 within the same report.
                if (in_array($base.'_type', $columnNames, true)) {
                    continue;
                }

                $target = $this->matchingTable($base, $tableNames);

                if ($target === null) {
                    continue;
                }

                yield new Finding(
                    code: $this->code(),
                    severity: $this->defaultSeverity(),
                    connection: $connection,
                    table: $table['name'],
                    column: $name,
                    message: "Column \"{$table['name']}.{$name}\" looks like a foreign key to \"{$target}\" but has no constraint.",
                    hint: 'A foreign key enforces referential integrity and, on MySQL, indexes the column. If this reference is intentionally unconstrained (for example across databases), add it to the doctor ignore list.',
                    suggestion: null,
                );
            }
        }
    }

    /**
     * The table name (plural or singular) a `*_id` base refers to, or null when
     * no such table exists in the snapshot.
     *
     * @param  list<string>  $tableNames
     */
    private function matchingTable(string $base, array $tableNames): ?string
    {
        if ($base === '') {
            return null;
        }

        foreach ([Str::plural($base), $base] as $candidate) {
            if (in_array($candidate, $tableNames, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Every column covered by a foreign key on the table.
     *
     * @param  array<string, mixed>  $table
     * @return list<string>
     */
    private function constrainedColumns(array $table): array
    {
        $columns = [];

        foreach ($table['foreign_keys'] ?? [] as $fk) {
            foreach ($fk['columns'] ?? [] as $column) {
                $columns[] = $column;
            }
        }

        return $columns;
    }
}
