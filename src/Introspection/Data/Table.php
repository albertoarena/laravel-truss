<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Introspection\Data;

/**
 * A table and its structure. `primaryKey` is the hoisted primary key (empty when
 * the table has none); it is NOT repeated in `indexes`, which holds only
 * non-primary indexes. Per-column PK/FK badges are derived at render time from
 * `primaryKey` and `foreignKeys`, not stored on columns.
 */
final readonly class Table
{
    /**
     * @param  list<Column>  $columns
     * @param  list<string>  $primaryKey
     * @param  list<Index>  $indexes
     * @param  list<ForeignKey>  $foreignKeys
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $primaryKey,
        public array $indexes,
        public array $foreignKeys,
    ) {}
}
