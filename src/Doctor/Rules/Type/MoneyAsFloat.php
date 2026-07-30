<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Rules\Type;

use AlbertoArena\Truss\Doctor\Category;
use AlbertoArena\Truss\Doctor\Confidence;
use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\Severity;

/**
 * TRUSS-TYP-001: a money-looking column stored as float or double. Binary
 * floating point cannot represent decimal money exactly, so totals drift.
 * Heuristic: the money-ness is inferred from the column name, so a non-money
 * float (a ratio, a weight) is not flagged.
 */
final class MoneyAsFloat implements Rule
{
    /** @var list<string> */
    private const MONEY_TERMS = ['price', 'cost', 'amount', 'total', 'balance', 'salary', 'fee', 'tax', 'discount'];

    public function code(): string
    {
        return 'TRUSS-TYP-001';
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
        return Severity::Error;
    }

    public function title(): string
    {
        return 'Money-looking column stored as float';
    }

    public function check(array $snapshot, string $connection): iterable
    {
        foreach ($snapshot['tables'] ?? [] as $table) {
            foreach ($table['columns'] ?? [] as $column) {
                if (! $this->isFloatingPoint($column['type']) || ! $this->looksLikeMoney($column['name'])) {
                    continue;
                }

                yield new Finding(
                    code: $this->code(),
                    severity: $this->defaultSeverity(),
                    connection: $connection,
                    table: $table['name'],
                    column: $column['name'],
                    message: "Column \"{$table['name']}.{$column['name']}\" holds money-looking values as {$column['type']}, which cannot represent money exactly.",
                    hint: 'Store money as decimal, or as an integer number of cents. Float and double introduce rounding errors that corrupt totals. Ignore this if the column is not actually money.',
                    suggestion: null,
                );
            }
        }
    }

    private function isFloatingPoint(string $type): bool
    {
        $type = strtolower($type);

        return str_contains($type, 'float') || str_contains($type, 'double') || str_contains($type, 'real');
    }

    private function looksLikeMoney(string $name): bool
    {
        $name = strtolower($name);

        foreach (self::MONEY_TERMS as $term) {
            if (str_contains($name, $term)) {
                return true;
            }
        }

        return false;
    }
}
