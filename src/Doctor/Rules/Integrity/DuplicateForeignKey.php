<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Rules\Integrity;

use AlbertoArena\Truss\Doctor\Category;
use AlbertoArena\Truss\Doctor\Confidence;
use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\Severity;

/**
 * TRUSS-INT-004: the same foreign key declared more than once, usually two
 * migrations that each add the same key, or a copy-paste. Every write checks
 * the constraint twice and schema diffs get confusing. Compared as the set of
 * column pairs and the referenced table, so composite keys are covered and
 * the order they were listed in does not matter. Each extra copy is reported
 * under its own constraint name; the first one declared is kept. Where the
 * database reports no constraint names (SQLite), the copies cannot be told
 * apart, so the key is reported once, under its columns.
 */
final class DuplicateForeignKey implements Rule
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
        return Severity::Warning;
    }

    public function title(): string
    {
        return 'Duplicate foreign key';
    }

    public function check(array $snapshot, string $connection): iterable
    {
        foreach ($snapshot['tables'] ?? [] as $table) {
            $seen = [];
            $reported = [];

            foreach ($table['foreign_keys'] ?? [] as $fk) {
                $key = $this->key($fk);

                if (! isset($seen[$key])) {
                    $seen[$key] = $fk['name'] ?? '';

                    continue;
                }

                $columns = implode(', ', $fk['columns']);
                $named = ($fk['name'] ?? '') !== '' && $seen[$key] !== '';

                if (! $named && isset($reported[$key])) {
                    continue;
                }

                $reported[$key] = true;

                yield new Finding(
                    code: $this->code(),
                    severity: $this->defaultSeverity(),
                    connection: $connection,
                    table: $table['name'],
                    column: $named ? $fk['name'] : $columns,
                    message: $named
                        ? "Foreign key \"{$fk['name']}\" duplicates \"{$seen[$key]}\"; both constrain ({$columns}) to \"{$fk['references_table']}\"."
                        : "The foreign key on ({$columns}) to \"{$fk['references_table']}\" is declared more than once.",
                    hint: 'The same constraint declared twice is checked twice on every write and makes schema diffs harder to read. Drop one.',
                    suggestion: null,
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $fk
     */
    private function key(array $fk): string
    {
        $pairs = [];

        foreach ($fk['columns'] as $index => $column) {
            $pairs[] = $column."\0".($fk['references_columns'][$index] ?? '');
        }

        sort($pairs);

        return $fk['references_table']."\0\0".implode("\0\0", $pairs);
    }
}
