<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Rules\Integrity;

use AlbertoArena\Truss\Doctor\Category;
use AlbertoArena\Truss\Doctor\Confidence;
use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\Severity;

/**
 * TRUSS-INT-009: a polymorphic column pair (`{name}_type` with a matching
 * `{name}_id`) that has no composite index leading with those two columns, the
 * index Laravel's morphs() creates. Without it, every polymorphic lookup scans
 * the table. Heuristic: a *_type column with no matching *_id is not treated as
 * a morph pair.
 */
final class PolymorphicWithoutIndex implements Rule
{
    public function code(): string
    {
        return 'TRUSS-INT-009';
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
        return 'Polymorphic columns without a composite index';
    }

    public function check(array $snapshot, string $connection): iterable
    {
        foreach ($snapshot['tables'] ?? [] as $table) {
            $columnNames = array_column($table['columns'] ?? [], 'name');

            foreach ($columnNames as $name) {
                if (! str_ends_with($name, '_type')) {
                    continue;
                }

                $idColumn = substr($name, 0, -5).'_id';

                if (! in_array($idColumn, $columnNames, true) || $this->hasLeadingIndex($table, $name, $idColumn)) {
                    continue;
                }

                yield new Finding(
                    code: $this->code(),
                    severity: $this->defaultSeverity(),
                    connection: $connection,
                    table: $table['name'],
                    column: $name,
                    message: "Polymorphic columns \"{$name}\" and \"{$idColumn}\" have no composite index, so morph lookups scan the table.",
                    hint: "Laravel's morphs() adds a composite index over the type and id columns. Without it every polymorphic relation query does a full table scan.",
                    suggestion: null,
                );
            }
        }
    }

    /**
     * Whether an index leads with exactly the type and id columns, in that order,
     * which is how morphs() builds it.
     *
     * @param  array<string, mixed>  $table
     */
    private function hasLeadingIndex(array $table, string $typeColumn, string $idColumn): bool
    {
        foreach ($table['indexes'] ?? [] as $index) {
            if (array_slice($index['columns'] ?? [], 0, 2) === [$typeColumn, $idColumn]) {
                return true;
            }
        }

        return false;
    }
}
