<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Rules\Index;

use AlbertoArena\Truss\Doctor\Category;
use AlbertoArena\Truss\Doctor\Confidence;
use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\Severity;

/**
 * TRUSS-IDX-002: two indexes over the same columns in the same order. The second
 * is dead weight: it costs writes and storage while serving no query the first
 * does not. Column order matters, so (a, b) and (b, a) are not duplicates.
 */
final class DuplicateIndex implements Rule
{
    public function code(): string
    {
        return 'TRUSS-IDX-002';
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
        return 'Exact duplicate index';
    }

    public function check(array $snapshot, string $connection): iterable
    {
        foreach ($snapshot['tables'] ?? [] as $table) {
            $seen = [];

            foreach ($table['indexes'] ?? [] as $index) {
                $key = implode(',', $index['columns'] ?? []);

                if (isset($seen[$key])) {
                    $columns = implode(', ', $index['columns'] ?? []);

                    yield new Finding(
                        code: $this->code(),
                        severity: $this->defaultSeverity(),
                        connection: $connection,
                        table: $table['name'],
                        column: $index['name'],
                        message: "Index \"{$index['name']}\" duplicates \"{$seen[$key]}\"; both cover ({$columns}).",
                        hint: 'Two indexes over the same columns cost writes and storage for no gain. Drop one.',
                        suggestion: null,
                    );

                    continue;
                }

                $seen[$key] = $index['name'];
            }
        }
    }
}
