<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Export;

use AlbertoArena\Truss\Export\Contracts\Generator;
use InvalidArgumentException;

/**
 * The internal export service: it takes the raw serialized snapshot tables,
 * applies the allow/block filters, imposes a fully deterministic ordering, then
 * hands the result to the format generator. Structure only, never row data.
 *
 * Splitting "select the tables" from "render them" lets the caller (the command)
 * detect an empty result and choose its own exit code before anything is
 * rendered. Determinism lives here, once, so every format inherits it and no
 * generator ever has to sort.
 */
class SchemaExporter
{
    /**
     * @var array<string, class-string<Generator>>
     */
    private const GENERATORS = [
        'dbml' => DbmlGenerator::class,
        'json' => JsonGenerator::class,
        'csv' => CsvGenerator::class,
        'markdown' => MarkdownGenerator::class,
        'mermaid' => MermaidGenerator::class,
    ];

    /**
     * @return list<string>
     */
    public static function formats(): array
    {
        return array_keys(self::GENERATORS);
    }

    public static function supports(string $format): bool
    {
        return isset(self::GENERATORS[$format]);
    }

    /**
     * Select and deterministically order the tables to export.
     *
     * Precedence: a table is kept when it is in the allowlist (or the allowlist
     * is empty), is not in the CLI blocklist, and is not config-excluded. Config
     * exclusions always win, so you cannot filter your way to an excluded table.
     *
     * @param  list<array<string, mixed>>  $tables  the raw snapshot tables
     * @param  list<string>  $only  --tables allowlist; empty means every table
     * @param  list<string>  $exclude  --exclude blocklist
     * @param  list<string>  $configExcluded  config excluded_tables, always removed
     * @return list<array<string, mixed>>
     */
    public function tablesFor(array $tables, array $only = [], array $exclude = [], array $configExcluded = []): array
    {
        $selected = array_values(array_filter($tables, function (array $table) use ($only, $exclude, $configExcluded): bool {
            $name = $table['name'];

            if ($only !== [] && ! in_array($name, $only, true)) {
                return false;
            }

            return ! in_array($name, $exclude, true) && ! in_array($name, $configExcluded, true);
        }));

        return $this->order($selected);
    }

    /**
     * Render already-selected, already-ordered tables in the requested format.
     *
     * @param  list<array<string, mixed>>  $tables
     */
    public function generate(string $format, array $tables): string
    {
        if (! self::supports($format)) {
            throw new InvalidArgumentException("Unsupported export format [{$format}].");
        }

        $generator = self::GENERATORS[$format];

        return (new $generator)->generate($tables);
    }

    /**
     * Impose the canonical ordering: tables alphabetical, foreign keys by source
     * column, indexes by name. Columns and primary-key order are left untouched
     * because their order carries meaning (declaration order, key order).
     *
     * @param  list<array<string, mixed>>  $tables
     * @return list<array<string, mixed>>
     */
    private function order(array $tables): array
    {
        usort($tables, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return array_map(function (array $table): array {
            $fks = $table['foreign_keys'] ?? [];
            usort($fks, fn (array $a, array $b): int => strcmp(implode(',', $a['columns']), implode(',', $b['columns'])));
            $table['foreign_keys'] = $fks;

            $indexes = $table['indexes'] ?? [];
            usort($indexes, fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
            $table['indexes'] = $indexes;

            return $table;
        }, $tables);
    }
}
