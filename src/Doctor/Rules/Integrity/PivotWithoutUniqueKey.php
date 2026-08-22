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
 * key on the pair, or a unique index over it, satisfies the rule.
 *
 * Two single-column foreign keys is necessary but NOT sufficient: a join table
 * carries its key pair and almost nothing else. That extra test exists because
 * "exactly two foreign keys" was once the whole definition, which made a
 * 36-column shopping cart a pivot and advised a unique constraint that would have
 * capped a customer at one cart. See docs/adr/0003-pivot-detection.md; the
 * thresholds below are fitted to real schemas and are not arbitrary.
 */
final class PivotWithoutUniqueKey implements Rule
{
    /**
     * Columns that never count against a table looking like a join table: the
     * conventional surrogate key and Laravel's timestamps.
     */
    private const NON_PAYLOAD = ['id', 'created_at', 'updated_at', 'deleted_at'];

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

        if (! $this->looksLikeJoinTable($table, $columns)) {
            return null;
        }

        return $columns;
    }

    /**
     * Whether the table carries its key pair and little else, which is what
     * separates a join table from an entity that happens to have two foreign
     * keys.
     *
     * The surrogate-key case is deliberately stricter. A table with its own
     * identity is presumed an entity unless it carries nothing at all beyond the
     * pair, which is what distinguishes `genre_song` (an id, two keys, a real
     * pivot) from `playlist_folders` (an id, two keys, and a name, an entity).
     * A table with no primary key, or whose primary key *is* the pair, is
     * unambiguously a join table and gets one payload column of latitude.
     *
     * @param  array<string, mixed>  $table
     * @param  list<string>  $pair
     */
    private function looksLikeJoinTable(array $table, array $pair): bool
    {
        $names = array_column($table['columns'] ?? [], 'name');
        $payload = count(array_diff($names, $pair, self::NON_PAYLOAD));
        $primaryKey = $table['primary_key'] ?? [];

        if ($primaryKey === [] || $this->sameSet($primaryKey, $pair)) {
            return $payload <= 1;
        }

        if ($primaryKey === ['id']) {
            return $payload === 0;
        }

        return false;
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
