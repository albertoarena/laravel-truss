<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Export\Contracts;

/**
 * Reads native schema comments (table and column) for a connection and returns
 * them as a flat lookup keyed by table name and "table.column". This is the only
 * DB-touching part of the annotation path; it is injected into the Annotator so
 * the merge logic stays pure and can be faked in tests.
 *
 * Native comments are part of the CREATE TABLE definition, not row content, so
 * reading them preserves the structure-only guarantee. Drivers without comment
 * support (SQLite, SQL Server) return an empty map.
 */
interface CommentReader
{
    /**
     * @return array<string, string> comments keyed by table name and "table.column"
     */
    public function read(?string $connection = null): array;
}
