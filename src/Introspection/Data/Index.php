<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Introspection\Data;

/**
 * A non-primary index. The primary key lives on {@see Table::$primaryKey}, not
 * here. `columns` is always an array so composite indexes are first-class.
 *
 * `columns` holds column names only. An index with an expression key part
 * also carries `parts`, every key part in order, because the column list alone
 * cannot tell an index on (tenant_id, lower(email)) from one on tenant_id.
 * A plain column index has no parts.
 */
final readonly class Index
{
    /**
     * @param  list<string>  $columns
     * @param  list<IndexPart>  $parts
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique,
        public array $parts = [],
    ) {}
}
