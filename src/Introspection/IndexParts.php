<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Introspection;

use AlbertoArena\Truss\Introspection\Data\IndexPart;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;

/**
 * The ordered key parts of every index on a table that contains an expression.
 *
 * Laravel's getIndexes() lists only the column parts of an index: the
 * expression in an index on (tenant_id, lower(email)) is dropped on every
 * driver, which leaves it indistinguishable from an index on tenant_id. This
 * reads the key parts from each driver's own catalogue instead. Sort order and
 * operator classes are left out, as they are for column parts.
 */
final class IndexParts
{
    /**
     * @return array<string, list<IndexPart>> keyed by lower-cased index name,
     *                                        as Laravel reports the name
     */
    public function forTable(Connection $connection, string $table): array
    {
        return match ($connection->getDriverName()) {
            'pgsql' => $this->postgres($connection, $table),
            'mysql', 'mariadb' => $this->mysql($connection, $table),
            'sqlite' => $this->sqlite($connection, $table),
            default => [],
        };
    }

    /**
     * @return array<string, list<IndexPart>>
     */
    private function postgres(Connection $connection, string $table): array
    {
        // A key part with attnum 0 is an expression, and the per-column form of
        // pg_get_indexdef prints just that part. INCLUDE columns sit past
        // indnkeyatts and are not key parts.
        $rows = $connection->select(
            'select ic.relname as name, a.attname as "column", '
            .'pg_get_indexdef(i.indexrelid, k.ord::int, true) as "expression" '
            .'from pg_index i '
            .'join pg_class tc on tc.oid = i.indrelid '
            .'join pg_namespace tn on tn.oid = tc.relnamespace '
            .'join pg_class ic on ic.oid = i.indexrelid '
            .'cross join lateral unnest(i.indkey) with ordinality as k(num, ord) '
            .'left join pg_attribute a on a.attrelid = i.indrelid and a.attnum = k.num and k.num > 0 '
            .'where tc.relname = ? and tn.nspname = current_schema() '
            .'and 0 = any(i.indkey::int2[]) and k.ord <= i.indnkeyatts '
            .'order by ic.relname, k.ord',
            [$table],
        );

        return $this->group($rows);
    }

    /**
     * @return array<string, list<IndexPart>>
     */
    private function mysql(Connection $connection, string $table): array
    {
        // MariaDB has no functional key parts, and MySQL before 8.0.13 has no
        // EXPRESSION column to read them from.
        if (($connection instanceof MySqlConnection && $connection->isMaria())
            || $connection->getDriverName() === 'mariadb'
            || $connection->selectOne(
                "select count(*) as n from information_schema.columns where table_schema = 'information_schema' "
                ."and table_name = 'STATISTICS' and column_name = 'EXPRESSION'"
            )->n == 0) {
            return [];
        }

        $rows = $connection->select(
            'select index_name as `name`, column_name as `column`, expression as `expression` '
            .'from information_schema.statistics '
            .'where table_schema = database() and table_name = ? and index_name in ('
            .'select index_name from information_schema.statistics '
            .'where table_schema = database() and table_name = ? and expression is not null) '
            .'order by index_name, seq_in_index',
            [$table, $table],
        );

        return $this->group($rows);
    }

    /**
     * @return array<string, list<IndexPart>>
     */
    private function sqlite(Connection $connection, string $table): array
    {
        // SQLite keeps no expression text in its catalogue, only the CREATE
        // INDEX statement, so the key list is read from that. pragma_index_xinfo
        // says which key parts are expressions (cid -2) and names the columns.
        $parts = [];

        $indexes = $connection->select(
            "select name, sql from sqlite_master where type = 'index' and tbl_name = ? and sql is not null",
            [$table],
        );

        foreach ($indexes as $index) {
            $keys = $connection->select(
                'select cid, name from pragma_index_xinfo(?) where key = 1 order by seqno',
                [$index->name],
            );

            if (! in_array(-2, array_map(fn (object $k): int => (int) $k->cid, $keys), true)) {
                continue;
            }

            $written = $this->sqliteKeyList((string) $index->sql);

            if (count($written) !== count($keys)) {
                continue;
            }

            $parts[strtolower((string) $index->name)] = array_map(
                fn (object $key, string $sql): IndexPart => (int) $key->cid === -2
                    ? IndexPart::expression($sql)
                    : IndexPart::column((string) $key->name),
                $keys,
                $written,
            );
        }

        return $parts;
    }

    /**
     * The key parts of a CREATE INDEX statement as written, split on the
     * top-level commas of its column list, with sort order, collation and
     * redundant wrapping parentheses removed.
     *
     * @return list<string>
     */
    private function sqliteKeyList(string $sql): array
    {
        if (preg_match('/\bON\s+(?:"(?:[^"]|"")*"|`[^`]*`|\[[^\]]*\]|[^\s(]+)\s*\(/i', $sql, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;

        for ($i = $m[0][1] + strlen($m[0][0]); $i < strlen($sql); $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                $current .= $char;
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`' || $char === '[') {
                $quote = $char === '[' ? ']' : $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && $depth-- === 0) {
                $parts[] = $current;

                break;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        return array_map($this->bareKeyPart(...), $parts);
    }

    private function bareKeyPart(string $part): string
    {
        $part = trim((string) preg_replace('/\s+(?:COLLATE\s+\S+\s*)?(?:ASC|DESC)?\s*$/i', '', ' '.trim($part)));
        $part = trim((string) preg_replace('/\s+COLLATE\s+\S+$/i', '', $part));

        while (str_starts_with($part, '(') && str_ends_with($part, ')') && $this->wrapsWhole($part)) {
            $part = trim(substr($part, 1, -1));
        }

        return $part;
    }

    /** Whether the opening parenthesis closes only at the very end. */
    private function wrapsWhole(string $part): bool
    {
        $depth = 0;

        for ($i = 0; $i < strlen($part); $i++) {
            $depth += match ($part[$i]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return $i === strlen($part) - 1;
            }
        }

        return false;
    }

    /**
     * @param  array<int, object>  $rows  name, column, expression per key part, in order
     * @return array<string, list<IndexPart>>
     */
    private function group(array $rows): array
    {
        $parts = [];

        foreach ($rows as $row) {
            $parts[strtolower((string) $row->name)][] = $row->column !== null
                ? IndexPart::column((string) $row->column)
                : IndexPart::expression((string) $row->expression);
        }

        return $parts;
    }
}
