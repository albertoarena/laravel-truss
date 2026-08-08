<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Export;

use AlbertoArena\Truss\Export\Contracts\Generator;

/**
 * A flat, spreadsheet-friendly view of every column across every table, with a
 * derived PK / FK key column mirroring the diagram badges. Structure only.
 *
 * The browser CSV export is per-table; the CLI prepends a `table` column so the
 * whole schema fits one sheet. A first-class server shape, pinned by its own
 * golden test rather than the JS cross-check.
 */
class CsvGenerator implements Generator
{
    private const HEADER = ['table', 'name', 'type', 'nullable', 'default', 'key'];

    public function generate(array $tables, array $notes = []): string
    {
        // The annotation column appears only when some column is annotated, so an
        // un-annotated export keeps the exact six-column shape.
        $describe = $this->hasColumnAnnotation($tables);

        $rows = [$describe ? [...self::HEADER, 'annotation'] : self::HEADER];

        foreach ($tables as $table) {
            $pk = $table['primary_key'] ?? [];
            $fk = array_merge(...(array_map(
                fn (array $f): array => $f['columns'],
                $table['foreign_keys'] ?? [],
            ) ?: [[]]));

            foreach ($table['columns'] ?? [] as $c) {
                $key = implode(', ', array_filter([
                    in_array($c['name'], $pk, true) ? 'PK' : null,
                    in_array($c['name'], $fk, true) ? 'FK' : null,
                ]));
                $row = [
                    $table['name'],
                    $c['name'],
                    $c['type'],
                    ($c['nullable'] ?? false) ? 'true' : 'false',
                    $c['default'] ?? '',
                    $key,
                ];
                if ($describe) {
                    $row[] = $c['annotation'] ?? '';
                }
                $rows[] = $row;
            }
        }

        return implode("\n", array_map(
            fn (array $row): string => implode(',', array_map($this->csvCell(...), $row)),
            $rows,
        ));
    }

    private function csvCell(mixed $value): string
    {
        $s = $value === null ? '' : (string) $value;

        return preg_match('/[",\n]/', $s) === 1 ? '"'.str_replace('"', '""', $s).'"' : $s;
    }

    /**
     * Whether any column across every table carries an annotation, deciding
     * whether the annotation column is rendered.
     *
     * @param  list<array<string, mixed>>  $tables
     */
    private function hasColumnAnnotation(array $tables): bool
    {
        foreach ($tables as $table) {
            foreach ($table['columns'] ?? [] as $column) {
                if (($column['annotation'] ?? null) !== null) {
                    return true;
                }
            }
        }

        return false;
    }
}
