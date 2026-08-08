<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Export;

use AlbertoArena\Truss\Export\Contracts\Generator;

/**
 * Markdown data dictionary: a title, then one section per table with a column
 * table and index / foreign-key lines. A faithful PHP port of
 * resources/js/markdown-export.js, pinned byte-for-byte by the cross-check test.
 *
 * The export is intentionally connection-agnostic (no "Connection:" line): the
 * CLI output must be identical whatever connection produced it, so the artifact
 * a CI job commits does not churn when the connection name changes.
 */
class MarkdownGenerator implements Generator
{
    public function generate(array $tables, array $notes = []): string
    {
        $intro = $notes !== []
            ? [implode("\n", array_map(fn (string $note): string => '> '.$this->flatten($note), $notes))]
            : [];
        $sections = array_map($this->tableToMarkdown(...), $tables);

        return implode("\n\n", ['# Data dictionary', ...$intro, ...$sections])."\n";
    }

    /**
     * @param  array<string, mixed>  $table
     */
    private function tableToMarkdown(array $table): string
    {
        $key = $this->keyResolver($table);
        $columns = $table['columns'] ?? [];

        // The Description column appears only when a column in this table is
        // annotated, so an un-annotated table renders exactly as before.
        $describe = $this->hasColumnAnnotation($columns);

        $head = '| Column | Type | Null | Default | Key |'.($describe ? ' Description |' : '');
        $rule = '| --- | --- | --- | --- | --- |'.($describe ? ' --- |' : '');

        $lines = ["## {$table['name']}"];
        if (($table['annotation'] ?? null) !== null) {
            $lines[] = '';
            $lines[] = '> '.$this->flatten($table['annotation']);
        }
        $lines = [...$lines, '', $head, $rule];

        foreach ($columns as $c) {
            $row = '| '.$this->cell($c['name'])
                .' | '.$this->cell($c['type'])
                .' | '.(($c['nullable'] ?? false) ? 'yes' : 'no')
                .' | '.$this->cell($c['default'] ?? null)
                .' | '.$key($c['name']).' |';
            if ($describe) {
                $row .= ' '.$this->cell($c['annotation'] ?? null).' |';
            }
            $lines[] = $row;
        }

        $indexes = $table['indexes'] ?? [];
        if ($indexes !== []) {
            $lines[] = '';
            $lines[] = 'Indexes: '.implode('; ', array_map(
                fn (array $i): string => "{$i['name']} (".implode(', ', $i['columns']).')'.($i['unique'] ? ' UNIQUE' : ''),
                $indexes,
            ));
        }

        $fks = $table['foreign_keys'] ?? [];
        if ($fks !== []) {
            $lines[] = '';
            $lines[] = 'Foreign keys: '.implode('; ', array_map($this->foreignKeyLine(...), $fks));
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $fk
     */
    private function foreignKeyLine(array $fk): string
    {
        $s = implode(', ', $fk['columns']).' -> '.$fk['references_table'].'.'.implode(', ', $fk['references_columns']);

        $acts = [];
        if ($fk['on_delete'] ?? null) {
            $acts[] = "on delete: {$fk['on_delete']}";
        }
        if ($fk['on_update'] ?? null) {
            $acts[] = "on update: {$fk['on_update']}";
        }
        if ($acts !== []) {
            $s .= ' ('.implode(', ', $acts).')';
        }

        return $s;
    }

    /**
     * Derive the PK / FK badge for a column, mirroring the diagram and CSV export.
     *
     * @param  array<string, mixed>  $table
     * @return callable(string): string
     */
    private function keyResolver(array $table): callable
    {
        $pk = $table['primary_key'] ?? [];
        $fk = array_merge(...array_map(
            fn (array $f): array => $f['columns'],
            $table['foreign_keys'] ?? [],
        ) ?: [[]]);

        return fn (string $name): string => implode(', ', array_filter([
            in_array($name, $pk, true) ? 'PK' : null,
            in_array($name, $fk, true) ? 'FK' : null,
        ]));
    }

    /**
     * A Markdown cell cannot contain a raw pipe or newline: escape the pipe and
     * flatten newlines.
     */
    private function cell(mixed $value): string
    {
        $s = $value === null ? '' : (string) $value;

        return str_replace(['|', "\n"], ['\\|', ' '], $s);
    }

    /**
     * Whether any column in the table carries an annotation, deciding whether the
     * Description column is rendered.
     *
     * @param  list<array<string, mixed>>  $columns
     */
    private function hasColumnAnnotation(array $columns): bool
    {
        foreach ($columns as $column) {
            if (($column['annotation'] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /** Collapse whitespace runs (including newlines) to single spaces. */
    private function flatten(string $value): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($value));
    }
}
