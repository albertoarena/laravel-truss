<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Rules\Index;

use AlbertoArena\Truss\Doctor\Category;
use AlbertoArena\Truss\Doctor\Confidence;
use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\Severity;

/**
 * TRUSS-IDX-005: a table with a soft-delete column (deleted_at) that no index
 * covers. Soft-deleted models add "where deleted_at is null" to every query, so
 * leaving it unindexed scans the table. Heuristic: on a small table it does not
 * matter, and deleted_at is often better as part of a composite index, so any
 * index that includes the column satisfies the rule.
 */
final class UnindexedSoftDelete implements Rule
{
    public function code(): string
    {
        return 'TRUSS-IDX-005';
    }

    public function category(): Category
    {
        return Category::Index;
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
        return 'Soft-delete column without an index';
    }

    public function check(array $snapshot, string $connection): iterable
    {
        foreach ($snapshot['tables'] ?? [] as $table) {
            $columnNames = array_column($table['columns'] ?? [], 'name');

            if (! in_array('deleted_at', $columnNames, true) || $this->anyIndexIncludes($table, 'deleted_at')) {
                continue;
            }

            yield new Finding(
                code: $this->code(),
                severity: $this->defaultSeverity(),
                connection: $connection,
                table: $table['name'],
                column: 'deleted_at',
                message: "Soft-delete column \"{$table['name']}.deleted_at\" is not indexed, so every query filters an unindexed column.",
                hint: 'Soft-deleted models add "where deleted_at is null" to every query. Indexing deleted_at, often as part of a composite index, keeps those scans cheap. Ignore this on small tables.',
                suggestion: null,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $table
     */
    private function anyIndexIncludes(array $table, string $column): bool
    {
        foreach ($table['indexes'] ?? [] as $index) {
            if (in_array($column, $index['columns'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }
}
