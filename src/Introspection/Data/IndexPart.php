<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Introspection\Data;

/**
 * One key part of an index that contains an expression: either a column name
 * or the expression text as the database prints it back (`lower(email)` on
 * Postgres and SQLite, ``lower(`email`)`` on MySQL). Exactly one is set.
 */
final readonly class IndexPart
{
    private function __construct(
        public ?string $column,
        public ?string $expression,
    ) {}

    public static function column(string $name): self
    {
        return new self($name, null);
    }

    public static function expression(string $sql): self
    {
        return new self(null, $sql);
    }
}
