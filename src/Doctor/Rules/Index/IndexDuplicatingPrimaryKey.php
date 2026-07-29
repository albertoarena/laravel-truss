<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Rules\Index;

use AlbertoArena\Truss\Doctor\Category;
use AlbertoArena\Truss\Doctor\Confidence;
use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\Severity;

/**
 * TRUSS-IDX-004: an index whose columns are exactly the primary key, in the same
 * order. The primary key is already a unique index, so this one is redundant.
 */
final class IndexDuplicatingPrimaryKey implements Rule
{
    public function code(): string
    {
        return 'TRUSS-IDX-004';
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
        return 'Index duplicates the primary key';
    }

    public function check(array $snapshot, string $connection): iterable
    {
        foreach ($snapshot['tables'] ?? [] as $table) {
            $primaryKey = $table['primary_key'] ?? [];

            if ($primaryKey === []) {
                continue;
            }

            foreach ($table['indexes'] ?? [] as $index) {
                if (($index['columns'] ?? []) !== $primaryKey) {
                    continue;
                }

                $columns = implode(', ', $primaryKey);

                yield new Finding(
                    code: $this->code(),
                    severity: $this->defaultSeverity(),
                    connection: $connection,
                    table: $table['name'],
                    column: $index['name'],
                    message: "Index \"{$index['name']}\" duplicates the primary key ({$columns}), which is already a unique index.",
                    hint: 'The primary key is indexed already, so a second index over the same columns is redundant. Drop it.',
                    suggestion: null,
                );
            }
        }
    }
}
