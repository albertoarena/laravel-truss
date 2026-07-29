<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Rules\Index;

use AlbertoArena\Truss\Doctor\Category;
use AlbertoArena\Truss\Doctor\Confidence;
use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\Severity;

/**
 * TRUSS-IDX-003: a non-unique index whose columns are a left prefix of another
 * index. A composite index already serves queries on its leading columns, so the
 * shorter one is redundant. Unique indexes are kept: they enforce a constraint
 * the longer index does not.
 */
final class RedundantPrefixIndex implements Rule
{
    public function code(): string
    {
        return 'TRUSS-IDX-003';
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
        return Severity::Warning;
    }

    public function title(): string
    {
        return 'Index is a left prefix of another index';
    }

    public function check(array $snapshot, string $connection): iterable
    {
        foreach ($snapshot['tables'] ?? [] as $table) {
            $indexes = $table['indexes'] ?? [];

            foreach ($indexes as $index) {
                if ($index['unique'] ?? false) {
                    continue;
                }

                $columns = $index['columns'] ?? [];
                $covered = $this->coveringIndex($indexes, $index, $columns);

                if ($covered === null) {
                    continue;
                }

                $shorter = implode(', ', $columns);
                $longer = implode(', ', $covered['columns']);

                yield new Finding(
                    code: $this->code(),
                    severity: $this->defaultSeverity(),
                    connection: $connection,
                    table: $table['name'],
                    column: $index['name'],
                    message: "Index \"{$index['name']}\" on ({$shorter}) is a left prefix of \"{$covered['name']}\" on ({$longer}), so it is redundant.",
                    hint: 'A composite index already serves queries on its leading columns, so the shorter index can be dropped.',
                    suggestion: null,
                );
            }
        }
    }

    /**
     * The first longer index that this index is a strict left prefix of, or null.
     *
     * @param  list<array<string, mixed>>  $indexes
     * @param  array<string, mixed>  $index
     * @param  list<string>  $columns
     * @return array<string, mixed>|null
     */
    private function coveringIndex(array $indexes, array $index, array $columns): ?array
    {
        $count = count($columns);

        foreach ($indexes as $other) {
            if ($other['name'] === $index['name']) {
                continue;
            }

            $otherColumns = $other['columns'] ?? [];

            if (count($otherColumns) > $count && array_slice($otherColumns, 0, $count) === $columns) {
                return $other;
            }
        }

        return null;
    }
}
