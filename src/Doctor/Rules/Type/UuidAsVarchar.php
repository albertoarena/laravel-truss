<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Doctor\Rules\Type;

use AlbertoArena\Truss\Doctor\Category;
use AlbertoArena\Truss\Doctor\Confidence;
use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\Severity;

/**
 * TRUSS-TYP-009: a column named `uuid`, `*_uuid`, `ulid` or `*_ulid` stored as
 * varchar(255). Heuristic: a UUID is 36 characters and a ULID 26, so the extra
 * width costs index space on exactly the columns most likely to be indexed. The
 * target differs per driver: char(36) or char(26) on MySQL and MariaDB, the
 * native uuid type (or char(26) for a ULID) on PostgreSQL. Skipped on SQLite,
 * which reads every string back as a bare varchar, so the type carries no signal.
 */
final class UuidAsVarchar implements Rule
{
    /** @var array<string, string> driver => how varchar(255) reads back */
    private const WIDE_TYPES = [
        'mysql' => 'varchar(255)',
        'mariadb' => 'varchar(255)',
        'pgsql' => 'character varying(255)',
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
        return 'UUID or ULID column stored as varchar(255)';
    }

    public function check(array $snapshot, string $connection): iterable
    {
        $driver = $snapshot['driver'] ?? null;
        $wide = self::WIDE_TYPES[$driver] ?? null;

        if ($wide === null) {
            return;
        }

        foreach ($snapshot['tables'] ?? [] as $table) {
            foreach ($table['columns'] ?? [] as $column) {
                $kind = $this->kind($column['name']);

                if ($kind === null || strtolower($column['type']) !== $wide) {
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
                    hint: "A {$label} has a fixed length, and the extra width costs index space on a column that is usually indexed. Use {$this->target($kind, $driver)}. Ignore this if the column holds something else.",
                    suggestion: null,
                );
            }
        }
    }

    private function kind(string $name): ?string
    {
        $name = strtolower($name);

        foreach (['uuid', 'ulid'] as $kind) {
            if ($name === $kind || str_ends_with($name, "_{$kind}")) {
                return $kind;
            }
        }

        return null;
    }

    private function target(string $kind, string $driver): string
    {
        if ($kind === 'ulid') {
            return 'char(26)';
        }

        return $driver === 'pgsql' ? 'the native uuid type' : 'char(36)';
    }
}
