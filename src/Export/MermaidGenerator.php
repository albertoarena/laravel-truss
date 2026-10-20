<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Export;

use AlbertoArena\Truss\Export\Contracts\Generator;

/**
 * A Mermaid `erDiagram` definition for the given tables. A PHP port of the
 * native-mode path of resources/js/mermaid-definition.js. It differs on purpose
 * where there is no render: no accessibility lines, and a rejected column name
 * becomes a readable token with the real name in its comment, where the
 * dashboard quotes it in backticks (see docs/DESIGN.md). Its output is parsed
 * by real Mermaid through the committed fixtures in tests/Fixtures/export.
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
        $ids = $this->tableIds(array_column($tables, 'name'));

        $entities = array_map(fn (array $table): string => $this->entityBlock($table, $ids[$table['name']]), $tables);

        // An edge is emitted only when BOTH endpoints are in the subset, so a
        // filtered/focused export never conjures a phantom entity.
        $relationships = [];
        foreach ($tables as $table) {
            foreach ($table['foreign_keys'] ?? [] as $fk) {
                if (! isset($ids[$fk['references_table']])) {
                    continue;
                }
                if ($fk['references_table'] === $table['name']) {
                    // Self-reference: shown as a "self-ref" column note (see
                    // entityBlock), not a self-loop edge (a large sweeping curve).
                    continue;
                }
                $label = $this->label($fk['name'] ?: implode('_', $fk['columns']));
                $relationships[] = "  {$ids[$fk['references_table']]} ||--o{ {$ids[$table['name']]} : \"{$label}\"";
            }
        }

        return implode("\n", ['erDiagram', ...$entities, ...$relationships]);
    }

    /**
     * @param  array<string, mixed>  $table
     */
    private function entityBlock(array $table, string $id): string
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
            $name = $this->columnName($column['name']);
            // There is no render to write a rewritten name back into, so the real
            // name goes in the comment, beside the self-ref note when there is one.
            $comments = array_filter([
                $name !== $column['name'] ? $this->text($column['name']) : null,
                in_array($column['name'], $selfRefColumns, true) ? 'self-ref' : null,
            ], fn (?string $comment): bool => $comment !== null);
            $note = $comments !== [] ? ' "'.implode(', ', $comments).'"' : '';

            return rtrim("    {$type} {$name}".($badge !== '' ? " {$badge}" : '').$note);
        }, $table['columns'] ?? []);
        $head = $id === $table['name'] ? $id : "{$id}[\"{$this->text($table['name'])}\"]";

        return implode("\n", ["  {$head} {", ...$lines, '  }']);
    }

    // Identifiers. Mermaid's ER lexer rejects some legal SQL names (a leading
    // digit, `pk`, `$`, whitespace, a table called `style`), and one rejected
    // name fails the whole diagram (issue #94). A name is rewritten only when
    // Mermaid would reject it or record something else, so a schema that parses
    // today keeps its exact output. The patterns port the ones in
    // mermaid-definition.js, which are pinned to real Mermaid; the fixtures in
    // tests/Fixtures/export pin this side to it, through a seeded fuzz as well.
    //
    // PCRE is not the JavaScript engine the lexer runs on, so its defaults are
    // spelled out: SPACE is JavaScript's \s (under `u` PHP's own is Unicode's
    // set, a different one), DOT is JavaScript's `.` (PCRE's also matches \r),
    // every anchored `$` has D (without it `$` matches before a final \n), and
    // there is no `i` where case matters (under `u` it folds the Kelvin sign
    // into ASCII, which the lexer does not). `µ` is listed because the lexer's
    // `i` folds it into range.

    private const SPACE = '\t\n\x{0B}\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    private const DOT = '[^\n\r\x{2028}\x{2029}]';

    private const ATTRIBUTE_CLASS = 'A-Za-z0-9\-_\[\]().,*\x{B5}\x{C0}-\x{10FFFF}';

    private const ATTRIBUTE_START = '*A-Za-z_\x{B5}\x{C0}-\x{10FFFF}';

    private const KEY_KEYWORD = '/^[PpFfUu][Kk](?![A-Za-z0-9_])/u';

    private const ENTITY_KEYWORD = '/^(?:(?:erdiagram|classdef|class|style|one|many|to)(?![a-z0-9_])|u[.-]|\.\.|\.-|--|-\.)/';

    private function isSafeColumn(string $name): bool
    {
        if (preg_match('/^['.self::SPACE.']/u', $name) === 1 || preg_match(self::KEY_KEYWORD, $name) === 1) {
            return false;
        }

        return preg_match('/^['.self::ATTRIBUTE_START.']['.self::ATTRIBUTE_CLASS.']*$/uD', $name) === 1
            // The lexer's generic-type rule: two `~`, no whitespace before the
            // first or after the last.
            || preg_match('/^[^'.self::SPACE.']*~'.self::DOT.'*~[^'.self::SPACE.']*$/uD', $name) === 1;
    }

    private function isSafeTable(string $name): bool
    {
        $lower = strtolower($name);
        if (preg_match('/^(?:[^\x00-\x7F]|[A-Za-z0-9_\-*.])+$/uD', $name) !== 1
            || preg_match('/^['.self::SPACE.']/u', $name) === 1
            || preg_match(self::ENTITY_KEYWORD, $lower) === 1
            || preg_match('/direction['.self::SPACE.']+(?:tb|bt|rl|lr)/u', $lower) === 1) {
            return false;
        }

        // A leading digit lexes as a cardinality or a number; only a whole
        // number is a name.
        return preg_match('/^[0-9]/', $name) !== 1 || preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $name) === 1;
    }

    /**
     * The attribute token for a column. There is no render to quote into (and
     * renderers older than Mermaid 11.16 lack backtick names), so a rejected
     * name becomes a readable token: whitespace and each rejected character
     * become `_`, and `_` goes in front of a bad first character or a key
     * keyword. The same as mermaidReadableName() in mermaid-definition.js,
     * held to it by the shared case list.
     */
    private function columnName(string $name): string
    {
        if ($this->isSafeColumn($name)) {
            return $name;
        }

        $token = (string) preg_replace('/[^'.self::ATTRIBUTE_CLASS.']|['.self::SPACE.']/u', '_', $name);
        if (preg_match('/^['.self::ATTRIBUTE_START.']/u', $token) !== 1) {
            $token = "_{$token}";
        }
        if (preg_match(self::KEY_KEYWORD, $token) === 1) {
            $token = "_{$token}";
        }

        return $token;
    }

    /**
     * The entity id for every table: the name itself when Mermaid accepts it,
     * otherwise `t_<name>` shown under its real name through an alias. Ids that
     * collide get `_2`, `_3` in table order; a real name is never taken over.
     *
     * @param  list<string>  $names
     * @return array<string, string>
     */
    private function tableIds(array $names): array
    {
        $ids = [];
        $taken = array_flip(array_filter($names, $this->isSafeTable(...)));
        foreach ($names as $name) {
            if (isset($taken[$name])) {
                $ids[$name] = $name;

                continue;
            }
            $base = 't_'.preg_replace('/[^A-Za-z0-9_\-*.\x{80}-\x{10FFFF}]|['.self::SPACE.']/u', '_', $name);
            $id = $base;
            for ($n = 2; isset($taken[$id]); $n++) {
                $id = "{$base}_{$n}";
            }
            $taken[$id] = true;
            $ids[$name] = $id;
        }

        return $ids;
    }

    /**
     * Text for a quoted relationship label: one line, no bare quote, and no
     * `direction TB` for the lexer to mistake for the layout directive (its `i`
     * is entity-coded, decoded again when it renders). The `i` flag is safe
     * here: no letter in the pattern has a non-ASCII case fold.
     */
    private function label(string $text): string
    {
        $text = str_replace('"', '#34;', (string) preg_replace('/[\r\n]+/', ' ', $text));

        return (string) preg_replace_callback(
            '/(?<=d)i(?=rection['.self::SPACE.']+(?:TB|BT|RL|LR))/iu',
            fn (array $m): string => '#'.ord($m[0]).';',
            $text,
        );
    }

    /**
     * Text for an alias label or a column comment: on top of label(), what the
     * quoted token cannot hold, and `#code;` text Mermaid would decode, are
     * entity-coded. Unlike the dashboard, `_` and `*` are left readable: no
     * lookup depends on this text, so markdown emphasis here is cosmetic.
     */
    private function text(string $text): string
    {
        return $this->label((string) preg_replace_callback(
            '/["%\\\\~]|#(?=[A-Za-z0-9_]+;)/',
            fn (array $m): string => '#'.ord($m[0]).';',
            $text,
        ));
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
