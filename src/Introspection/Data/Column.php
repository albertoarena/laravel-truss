<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Introspection\Data;

/**
 * A single column. `type` is the native full type exactly as the database
 * reports it (e.g. "varchar(255)", "bigint unsigned") — never a reverse-mapped
 * Laravel migration verb. `default` is part of the table structure, not row data.
 */
final readonly class Column
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable,
        public ?string $default,
    ) {}
}
