<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Rules\Integrity;

use AlbertoArena\Truss\Doctor\Category;
use AlbertoArena\Truss\Doctor\Confidence;
use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\Severity;

/**
 * TRUSS-INT-007: a two-foreign-key pivot table with no unique constraint on the
 * key pair, so the same relationship can be stored twice. A composite primary
 * key on the pair, or a unique index over it, satisfies the rule. Only tables
 * with exactly two single-column foreign keys are considered.
 */
final class PivotWithoutUniqueKey implements Rule
{
    public function code(): string
    {
        return 'TRUSS-INT-007';
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
        return Severity::Warning;
    }

    public function title(): string
    {
        return 'Pivot table without a unique key on its foreign-key pair';
    }

    public function check(array $snapshot, string $connection): iterable
    {
        foreach ($snapshot['tables'] ?? [] as $table) {
            $pair = $this->pivotPair($table);

            if ($pair === null || $this->hasUniqueOnPair($table, $pair)) {
                continue;
            }

            yield new Finding(
                code: $this->code(),
                severity: $this->defaultSeverity(),
                connection: $connection,
                table: $table['name'],
                column: null,
                message: "Pivot table \"{$table['name']}\" has no unique key on ({$pair[0]}, {$pair[1]}), so duplicate pairs are possible.",
                hint: 'A many-to-many pivot should put a unique constraint, or a composite primary key, on its two foreign keys, otherwise the same relationship can be stored twice.',
                suggestion: null,
            );
        }
    }

    /**
     * The two foreign-key columns when the table is a classic pivot (exactly two
     * single-column foreign keys), or null when it is not.
     *
     * @param  array<string, mixed>  $table
     * @return list<string>|null
     */
    private function pivotPair(array $table): ?array
    {
        $foreignKeys = $table['foreign_keys'] ?? [];

        if (count($foreignKeys) !== 2) {
            return null;
        }

        $columns = [];
        foreach ($foreignKeys as $foreignKey) {
            if (count($foreignKey['columns']) !== 1) {
                return null;
            }
            $columns[] = $foreignKey['columns'][0];
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $table
     * @param  list<string>  $pair
     */
    private function hasUniqueOnPair(array $table, array $pair): bool
    {
        if ($this->sameSet($table['primary_key'] ?? [], $pair)) {
            return true;
        }

        foreach ($table['indexes'] ?? [] as $index) {
            if (($index['unique'] ?? false) && $this->sameSet($index['columns'] ?? [], $pair)) {
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

        return $a === $b;
    }
}
