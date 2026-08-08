<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Export;

use AlbertoArena\Truss\Export\Contracts\Generator;

/**
 * A bespoke plaintext format tuned for feeding a coding agent: one indented line
 * per column, foreign keys inline with `->`, annotations indented beneath the
 * column they describe, and a short header with the table count and any global
 * notes. It trades the ceremony of DBML/Markdown for density.
 *
 * Its nullability convention is inverted from SQL for compactness: a column is
 * not-null unless marked `null`, so the common case costs no tokens. Structure
 * only, deterministic, and free of any metadata that changes between runs (no
 * connection, no timestamp), so two runs on the same schema are byte-identical.
 */
class LlmGenerator implements Generator
{
    public function generate(array $tables, array $notes = []): string
    {
        $header = ['# '.count($tables).' '.(count($tables) === 1 ? 'table' : 'tables')];
        foreach ($notes as $note) {
            $header[] = '# note: '.$this->flatten($note);
        }

        $blocks = array_map($this->tableBlock(...), $tables);

        return implode("\n", [...$header, '', ...$this->join($blocks)]);
    }

    /**
     * @param  array<string, mixed>  $table
     */
    private function tableBlock(array $table): string
    {
        $pk = $table['primary_key'] ?? [];
        $uniqueColumns = $this->uniqueColumns($table);
        $references = $this->references($table);

        $heading = $table['name'];
        if (($table['annotation'] ?? null) !== null) {
            $heading .= '  -- '.$this->flatten($table['annotation']);
        }

        $lines = [$heading];
        foreach ($table['columns'] ?? [] as $column) {
            $name = $column['name'];

            $markers = rtrim(
                '  '.$name.' '.((string) $column['type'])
                .(($column['nullable'] ?? false) ? ' null' : '')
                .(in_array($name, $pk, true) ? ' pk' : '')
                .(in_array($name, $uniqueColumns, true) ? ' unique' : '')
                .(isset($references[$name]) ? ' -> '.$references[$name] : '')
            );
            $lines[] = $markers;

            if (($column['annotation'] ?? null) !== null) {
                $lines[] = '    # '.$this->flatten($column['annotation']);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Columns that are the sole column of a unique index, so they can be marked
     * `unique` inline.
     *
     * @param  array<string, mixed>  $table
     * @return list<string>
     */
    private function uniqueColumns(array $table): array
    {
        $columns = [];
        foreach ($table['indexes'] ?? [] as $index) {
            if (($index['unique'] ?? false) && count($index['columns']) === 1) {
                $columns[] = $index['columns'][0];
            }
        }

        return $columns;
    }

    /**
     * Map each foreign-key source column to its "table.column" target, paired
     * positionally so composite keys map column by column.
     *
     * @param  array<string, mixed>  $table
     * @return array<string, string>
     */
    private function references(array $table): array
    {
        $map = [];
        foreach ($table['foreign_keys'] ?? [] as $foreignKey) {
            foreach ($foreignKey['columns'] as $i => $column) {
                $target = $foreignKey['references_columns'][$i] ?? $foreignKey['references_columns'][0];
                $map[$column] = $foreignKey['references_table'].'.'.$target;
            }
        }

        return $map;
    }

    /**
     * Separate table blocks with a blank line, without adding trailing space.
     *
     * @param  list<string>  $blocks
     * @return list<string>
     */
    private function join(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $i => $block) {
            if ($i > 0) {
                $out[] = '';
            }
            $out[] = $block;
        }

        return $out;
    }

    private function flatten(string $value): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($value));
    }
}
