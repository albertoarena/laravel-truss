<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Rules\Type;

use AlbertoArena\Truss\Doctor\Category;
use AlbertoArena\Truss\Doctor\Confidence;
use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\Severity;

/**
 * TRUSS-TYP-002: an `is_*` or `has_*` column, which reads as a yes/no flag,
 * stored as a character or text type. Heuristic: booleans are clearer and
 * smaller as a boolean (tinyint(1) on MySQL); a column that genuinely holds more
 * than two values can be ignored.
 */
final class BooleanAsString implements Rule
{
    public function code(): string
    {
        return 'TRUSS-TYP-002';
    }

    public function category(): Category
    {
        return Category::Type;
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
        return 'Boolean-looking column stored as text';
    }

    public function check(array $snapshot, string $connection): iterable
    {
        foreach ($snapshot['tables'] ?? [] as $table) {
            foreach ($table['columns'] ?? [] as $column) {
                $name = strtolower($column['name']);

                if (! str_starts_with($name, 'is_') && ! str_starts_with($name, 'has_')) {
                    continue;
                }

                if (! $this->isTextType($column['type'])) {
                    continue;
                }

                yield new Finding(
                    code: $this->code(),
                    severity: $this->defaultSeverity(),
                    connection: $connection,
                    table: $table['name'],
                    column: $column['name'],
                    message: "Column \"{$table['name']}.{$column['name']}\" reads as a yes/no flag but is stored as {$column['type']}.",
                    hint: 'A boolean-looking column is clearer and smaller as a boolean (tinyint(1) on MySQL). Ignore this if it genuinely holds more than two values.',
                    suggestion: null,
                );
            }
        }
    }

    private function isTextType(string $type): bool
    {
        $type = strtolower($type);

        return str_contains($type, 'char') || str_contains($type, 'text');
    }
}
