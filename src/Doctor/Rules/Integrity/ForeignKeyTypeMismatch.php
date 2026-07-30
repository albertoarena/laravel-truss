<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Rules\Integrity;

use AlbertoArena\Truss\Doctor\Category;
use AlbertoArena\Truss\Doctor\Confidence;
use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\Severity;

/**
 * TRUSS-INT-003: a foreign key whose column type does not match the type of the
 * key it references (a signed int pointing at a bigint unsigned key, say). The
 * constraint can fail to create, and mismatched types make joins slower.
 * Compared per column, so composite keys are covered. Skipped when the
 * referenced table is not in the snapshot (excluded, or another database).
 */
final class ForeignKeyTypeMismatch implements Rule
{
    public function code(): string
    {
        return 'TRUSS-INT-003';
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
        return Severity::Error;
    }

    public function title(): string
    {
        return 'Foreign key type does not match the referenced key';
    }

    public function check(array $snapshot, string $connection): iterable
    {
        $byName = [];
        foreach ($snapshot['tables'] ?? [] as $table) {
            $byName[$table['name']] = $table;
        }

        foreach ($byName as $table) {
            foreach ($table['foreign_keys'] ?? [] as $fk) {
                $referenced = $byName[$fk['references_table']] ?? null;

                if ($referenced === null) {
                    continue;
                }

                foreach ($fk['columns'] as $index => $column) {
                    $referencedColumn = $fk['references_columns'][$index] ?? null;

                    if ($referencedColumn === null) {
                        continue;
                    }

                    $localType = $this->columnType($table, $column);
                    $referencedType = $this->columnType($referenced, $referencedColumn);

                    if ($localType === null || $referencedType === null || $localType === $referencedType) {
                        continue;
                    }

                    yield new Finding(
                        code: $this->code(),
                        severity: $this->defaultSeverity(),
                        connection: $connection,
                        table: $table['name'],
                        column: $column,
                        message: "Foreign key \"{$table['name']}.{$column}\" is {$localType} but references \"{$fk['references_table']}.{$referencedColumn}\" which is {$referencedType}.",
                        hint: 'A foreign key column should have the same type as the key it references, or the constraint can fail to create and joins are slower. Signed versus unsigned counts as a mismatch.',
                        suggestion: null,
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $table
     */
    private function columnType(array $table, string $column): ?string
    {
        foreach ($table['columns'] ?? [] as $candidate) {
            if ($candidate['name'] === $column) {
                return $candidate['type'];
            }
        }

        return null;
    }
}
