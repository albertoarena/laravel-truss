<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Rules\Index;

use AlbertoArena\Truss\Doctor\Category;
use AlbertoArena\Truss\Doctor\Confidence;
use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\Severity;

/**
 * TRUSS-IDX-006: a column whose name reads like a unique identifier (email, slug,
 * uuid, token) but with no unique constraint. Heuristic: a column appearing in
 * any unique index (including a scoped composite, such as email unique per team)
 * or as the sole primary key satisfies the rule, and duplicates may be intended,
 * so it stays off the recommended default.
 */
final class MissingUniqueConstraint implements Rule
{
    /** @var list<string> */
    private const IDENTIFIERS = ['email', 'slug', 'uuid', 'token'];

    public function code(): string
    {
        return 'TRUSS-IDX-006';
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
        return 'Identifier column without a unique constraint';
    }

    public function check(array $snapshot, string $connection): iterable
    {
        foreach ($snapshot['tables'] ?? [] as $table) {
            $uniqueColumns = $this->uniquelyConstrainedColumns($table);
            $primaryKey = $table['primary_key'] ?? [];

            foreach ($table['columns'] ?? [] as $column) {
                $name = $column['name'];

                if (! in_array(strtolower($name), self::IDENTIFIERS, true)) {
                    continue;
                }

                if (in_array($name, $uniqueColumns, true) || $primaryKey === [$name]) {
                    continue;
                }

                yield new Finding(
                    code: $this->code(),
                    severity: $this->defaultSeverity(),
                    connection: $connection,
                    table: $table['name'],
                    column: $name,
                    message: "Column \"{$table['name']}.{$name}\" looks like a unique identifier but has no unique constraint.",
                    hint: 'Columns like email, slug, uuid, and token are usually unique. A unique index prevents duplicates and speeds lookups. Add one, or ignore this if duplicates are intended.',
                    suggestion: null,
                );
            }
        }
    }

    /**
     * Every column that appears in a unique index on the table.
     *
     * @param  array<string, mixed>  $table
     * @return list<string>
     */
    private function uniquelyConstrainedColumns(array $table): array
    {
        $columns = [];

        foreach ($table['indexes'] ?? [] as $index) {
            if ($index['unique'] ?? false) {
                foreach ($index['columns'] ?? [] as $column) {
                    $columns[] = $column;
                }
            }
        }

        return $columns;
    }
}
