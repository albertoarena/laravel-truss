<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Export;

use AlbertoArena\Truss\Export\Contracts\Generator;

/**
 * DBML serializer: a Table block per table, then Ref lines for the foreign keys.
 * Opens in dbdiagram.io and other DBML tools, which read DBML as a standard.
 *
 * A faithful PHP port of resources/js/dbml-export.js. The two must agree
 * byte-for-byte while both exist (the browser download still uses the JS
 * version); the cross-check test pins that. Type mapping is deliberately lossy:
 * the native type passes through, quoted only when it would otherwise misparse.
 */
class DbmlGenerator implements Generator
{
    public function generate(array $tables, array $notes = []): string
    {
        $header = $notes !== [] ? [$this->projectBlock($notes)] : [];
        $blocks = array_map($this->tableToDbml(...), $tables);
        $refs = $this->refLines($tables);

        return implode("\n\n", [...$header, ...$blocks, ...$refs])."\n";
    }

    /**
     * Global notes as a DBML Project note (the format's header block). Only
     * emitted when notes exist, so an un-annotated export is unchanged.
     *
     * @param  list<string>  $notes
     */
    private function projectBlock(array $notes): string
    {
        $body = implode("\n", array_map(fn (string $note): string => '    '.$note, $notes));

        return "Project truss {\n  Note: '''\n{$body}\n  '''\n}";
    }

    /**
     * @param  array<string, mixed>  $table
     */
    private function tableToDbml(array $table): string
    {
        $lines = ["Table {$table['name']} {"];

        foreach ($table['columns'] ?? [] as $column) {
            $lines[] = $this->columnLine($table, $column);
        }

        $idx = [];
        $pk = $table['primary_key'] ?? [];
        // A composite PK is expressed in the indexes block, not on the column.
        if (count($pk) > 1) {
            $idx[] = '    ('.implode(', ', $pk).') [pk]';
        }
        foreach ($table['indexes'] ?? [] as $index) {
            $cols = count($index['columns']) > 1
                ? '('.implode(', ', $index['columns']).')'
                : $index['columns'][0];
            $idx[] = '    '.$cols.($index['unique'] ? ' [unique]' : '');
        }
        if ($idx !== []) {
            $lines = [...$lines, '', '  indexes {', ...$idx, '  }'];
        }

        if (($table['annotation'] ?? null) !== null) {
            $lines[] = '  Note: '.$this->noteToken($table['annotation']);
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $table
     * @param  array<string, mixed>  $col
     */
    private function columnLine(array $table, array $col): string
    {
        $pk = $table['primary_key'] ?? [];
        $solePk = count($pk) === 1 && $pk[0] === $col['name'];

        $parts = [];
        // pk implies not null, so a sole PK never also emits [not null].
        if ($solePk) {
            $parts[] = 'pk';
        } elseif (! ($col['nullable'] ?? false)) {
            $parts[] = 'not null';
        }
        if (($col['default'] ?? null) !== null) {
            $parts[] = 'default: '.$this->defaultToken($col['default']);
        }
        if (($col['annotation'] ?? null) !== null) {
            $parts[] = 'note: '.$this->noteToken($col['annotation']);
        }

        $settings = $parts !== [] ? ' ['.implode(', ', $parts).']' : '';

        return '  '.$col['name'].' '.$this->typeToken($col['type']).$settings;
    }

    /**
     * A single-quoted DBML note. Newlines are flattened to spaces (a bare note is
     * single-line) and single quotes are escaped so the value cannot break out.
     */
    private function noteToken(string $note): string
    {
        $flat = preg_replace('/\s*\n\s*/', ' ', trim($note));

        return "'".str_replace("'", "\\'", (string) $flat)."'";
    }

    private function typeToken(?string $type): string
    {
        $t = (string) ($type ?? '');

        return preg_match('/[\s,"\']/', $t) === 1
            ? '"'.str_replace('"', '\\"', $t).'"'
            : $t;
    }

    private function defaultToken(mixed $value): string
    {
        $s = (string) $value;

        if (preg_match('/^-?\d+(\.\d+)?$/', $s) === 1) {
            return $s;                                    // number, bare
        }
        if (preg_match('/^(true|false|null)$/i', $s) === 1) {
            return strtolower($s);                        // boolean/null keyword
        }
        if (preg_match('/^current_timestamp/i', $s) === 1 || str_contains($s, '(')) {
            return '`'.$s.'`';                            // SQL expression
        }

        return "'".str_replace("'", "\\'", $s)."'";       // string literal
    }

    /**
     * Ref lines are emitted only when the referenced table is also in the export
     * set, so a filtered/focused export never produces a dangling (invalid) Ref.
     *
     * @param  list<array<string, mixed>>  $tables
     * @return list<string>
     */
    private function refLines(array $tables): array
    {
        $present = array_flip(array_column($tables, 'name'));
        $refs = [];

        foreach ($tables as $table) {
            foreach ($table['foreign_keys'] ?? [] as $fk) {
                if (! isset($present[$fk['references_table']])) {
                    continue;
                }
                $src = count($fk['columns']) > 1
                    ? '('.implode(', ', $fk['columns']).')'
                    : $fk['columns'][0];
                $tgt = count($fk['references_columns']) > 1
                    ? '('.implode(', ', $fk['references_columns']).')'
                    : $fk['references_columns'][0];
                $refs[] = "Ref: {$table['name']}.{$src} > {$fk['references_table']}.{$tgt}";
            }
        }

        return $refs;
    }
}
