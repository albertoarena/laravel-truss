<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Introspection\Data;

/**
 * A non-primary index. The primary key lives on {@see Table::$primaryKey}, not
 * here. `columns` is always an array so composite indexes are first-class.
 */
final readonly class Index
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique,
    ) {}
}
