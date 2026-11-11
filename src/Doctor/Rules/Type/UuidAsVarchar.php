<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Rules\Type;

use AlbertoArena\Truss\Doctor\Category;
use AlbertoArena\Truss\Doctor\Confidence;
use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\Severity;

/**
 * TRUSS-TYP-009: a column named `uuid` or `ulid`, or a `*_uuid` / `*_ulid`
 * column that is part of a foreign key, stored as a varchar wider than the
 * identifier: more than 36 characters for a UUID, or 26 for a ULID. That
 * covers Laravel's string() default of 255 and the 191 of
 * Schema::defaultStringLength(191). A plain `*_uuid` column is left alone: in
 * the field study every one held somebody else's identifier (a CardDAV UID, a
 * handle an author wrote), which is not a UUID and is wide on purpose, while a
 * foreign key column holds this schema's own. Heuristic: the extra width
 * costs index space on exactly the columns most likely to be indexed. The
 * target differs per driver: the native uuid type on PostgreSQL and MariaDB
 * 10.7+, char(36) on MySQL, and char(26) for a ULID everywhere. Skipped on
 * SQLite, which reads every string back as a bare varchar, so the type
 * carries no signal.
 */
final class UuidAsVarchar implements Rule
{
    /** @var array<string, int> identifier => its fixed length */
    private const LENGTHS = ['uuid' => 36, 'ulid' => 26];

    /** @var array<string, string> driver => how a varchar reads back, before the length */
    private const VARCHAR = [
        'mysql' => 'varchar',
        'mariadb' => 'varchar',
        'pgsql' => 'character varying',
    ];

    public function code(): string
    {
        return 'TRUSS-TYP-009';
    }

    public function category(): Category
    {
        return Category::Type;
    }

    public function confidence(): Confidence
    {
        return Confidence::Heuristic;
    }

    public function defaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function title(): string
    {
        return 'UUID or ULID column stored as a wider varchar';
    }

    public function check(array $snapshot, string $connection): iterable
    {
        $driver = $snapshot['driver'] ?? null;
        $varchar = self::VARCHAR[$driver] ?? null;

        if ($varchar === null) {
            return;
        }

        foreach ($snapshot['tables'] ?? [] as $table) {
            $foreignKeyColumns = [];
            foreach ($table['foreign_keys'] ?? [] as $fk) {
                foreach ($fk['columns'] as $fkColumn) {
                    $foreignKeyColumns[strtolower($fkColumn)] = true;
                }
            }

            foreach ($table['columns'] ?? [] as $column) {
                $kind = $this->kind($column['name'], $foreignKeyColumns);
                $length = $this->varcharLength($column['type'], $varchar);

                if ($kind === null || $length === null || $length <= self::LENGTHS[$kind]) {
                    continue;
                }

                $label = strtoupper($kind);

                yield new Finding(
                    code: $this->code(),
                    severity: $this->defaultSeverity(),
                    connection: $connection,
                    table: $table['name'],
                    column: $column['name'],
                    message: "Column \"{$table['name']}.{$column['name']}\" reads as a {$label} but is stored as {$column['type']}.",
                    hint: "A {$label} has a fixed length of ".self::LENGTHS[$kind].", and the extra width costs index space on a column that is usually indexed. Use {$this->target($kind, $driver)}. Ignore this if the column holds something else.",
                    suggestion: null,
                );
            }
        }
    }

    /** @param array<string, true> $foreignKeyColumns */
    private function kind(string $name, array $foreignKeyColumns): ?string
    {
        $name = strtolower($name);

        foreach (array_keys(self::LENGTHS) as $kind) {
            if ($name === $kind || (str_ends_with($name, "_{$kind}") && isset($foreignKeyColumns[$name]))) {
                return $kind;
            }
        }

        return null;
    }

    private function varcharLength(string $type, string $varchar): ?int
    {
        if (preg_match('/^'.preg_quote($varchar, '/').'\((\d+)\)$/', strtolower($type), $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }

    private function target(string $kind, string $driver): string
    {
        if ($kind === 'ulid') {
            return 'char(26)';
        }

        return match ($driver) {
            'pgsql' => 'the native uuid type',
            'mariadb' => 'the native uuid type (MariaDB 10.7 and later), or char(36)',
            default => 'char(36)',
        };
    }
}
