<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Export;

use AlbertoArena\Truss\Export\Contracts\Generator;

/**
 * A Mermaid `erDiagram` definition for the given tables. A faithful PHP port of
 * the native-mode path of resources/js/mermaid-definition.js, pinned byte-for-
 * byte by the cross-check test.
 *
 * Native types only: the browser's "laravel" short-label mode is an interactive
 * UI toggle with no CLI flag in this phase, so it is not ported here. Per-column
 * PK/FK badges are derived from primary_key and foreign_keys[].columns.
 */
class MermaidGenerator implements Generator
{
    public function generate(array $tables, array $notes = []): string
    {
        // Mermaid has no clean place for annotations or global notes, so both are
        // omitted here by design; the other formats carry them.
        $present = array_flip(array_column($tables, 'name'));

        $entities = array_map($this->entityBlock(...), $tables);

        // An edge is emitted only when BOTH endpoints are in the subset, so a
        // filtered/focused export never conjures a phantom entity.
        $relationships = [];
        foreach ($tables as $table) {
            foreach ($table['foreign_keys'] ?? [] as $fk) {
                if (! isset($present[$fk['references_table']])) {
                    continue;
                }
                if ($fk['references_table'] === $table['name']) {
                    // Self-reference: shown as a "self-ref" column note (see
                    // entityBlock), not a self-loop edge (a large sweeping curve).
                    continue;
                }
                $label = $fk['name'] ?: implode('_', $fk['columns']);
                $relationships[] = "  {$fk['references_table']} ||--o{ {$table['name']} : \"{$label}\"";
            }
        }

        return implode("\n", ['erDiagram', ...$entities, ...$relationships]);
    }

    /**
     * @param  array<string, mixed>  $table
     */
    private function entityBlock(array $table): string
    {
        $primaryKey = $table['primary_key'] ?? [];
        $foreignKeys = $table['foreign_keys'] ?? [];
        $fkColumns = array_merge(...(array_map(
            fn (array $fk): array => $fk['columns'],
            $foreignKeys,
        ) ?: [[]]));
        $selfRefColumns = array_merge(...(array_map(
            fn (array $fk): array => $fk['columns'],
            array_filter($foreignKeys, fn (array $fk): bool => $fk['references_table'] === $table['name']),
        ) ?: [[]]));

        $lines = array_map(function (array $column) use ($primaryKey, $fkColumns, $selfRefColumns): string {
            $type = $this->mermaidType($column['type']);
            $badge = $this->keyBadge($column['name'], $primaryKey, $fkColumns);
            $note = in_array($column['name'], $selfRefColumns, true) ? ' "self-ref"' : '';

            return rtrim("    {$type} {$column['name']}".($badge !== '' ? " {$badge}" : '').$note);
        }, $table['columns'] ?? []);

        return implode("\n", ["  {$table['name']} {", ...$lines, '  }']);
    }

    /**
     * Collapse a native type into a single Mermaid-safe attribute-type token.
     * Mermaid splits attributes on whitespace, so `bigint unsigned` and
     * `varchar(255)` must become one word. enum/set value lists collapse to the
     * keyword so a long list does not blow out the column width.
     */
    private function mermaidType(?string $nativeType): string
    {
        $label = preg_replace('/^\s*(enum|set)\b[\s\S]*$/i', '$1', (string) $nativeType);
        $label = preg_replace('/[^A-Za-z0-9]+/', '_', trim((string) $label));
        $label = preg_replace('/^_+|_+$/', '', (string) $label);

        return $label !== '' && $label !== null ? $label : 'unknown';
    }

    /**
     * @param  list<string>  $primaryKey
     * @param  list<string>  $foreignKeyColumns
     */
    private function keyBadge(string $columnName, array $primaryKey, array $foreignKeyColumns): string
    {
        $badges = [];
        if (in_array($columnName, $primaryKey, true)) {
            $badges[] = 'PK';
        }
        if (in_array($columnName, $foreignKeyColumns, true)) {
            $badges[] = 'FK';
        }

        return implode(', ', $badges);
    }
}
