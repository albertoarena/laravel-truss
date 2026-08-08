<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Export;

/**
 * Compact mode: drop the DBA detail that spends tokens without adding meaning a
 * coding agent needs. A snapshot transform applied before the generator runs, so
 * no generator learns about compactness; it just receives a leaner snapshot.
 *
 * Dropped: column defaults and non-unique index definitions. A unique index is
 * kept but reduced to its bare marker (the name, an internal DBA detail, is
 * cleared), so the uniqueness constraint survives while the index definition
 * does not. Kept unchanged: every table, column, normalised type, nullability,
 * primary key, unique constraint, foreign key, and any annotations.
 *
 * It loses no table, column, or foreign key, only detail, so full and compact
 * describe the same schema at different verbosity.
 */
final class CompactTransform
{
    /**
     * @param  list<array<string, mixed>>  $tables
     * @return list<array<string, mixed>>
     */
    public function apply(array $tables): array
    {
        return array_map(function (array $table): array {
            $table['columns'] = array_map(function (array $column): array {
                unset($column['default']);

                return $column;
            }, $table['columns'] ?? []);

            $table['indexes'] = array_values(array_map(
                fn (array $index): array => [...$index, 'name' => ''],
                array_filter(
                    $table['indexes'] ?? [],
                    fn (array $index): bool => (bool) ($index['unique'] ?? false),
                ),
            ));

            return $table;
        }, $tables);
    }
}
