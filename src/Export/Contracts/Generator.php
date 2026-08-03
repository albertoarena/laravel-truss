<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Export\Contracts;

/**
 * Turns a list of serialized tables (the SchemaSerializer array shape) into a
 * single export string in one format. Generators are pure and stateless: they
 * render their input verbatim, in the order given. Deterministic ordering and
 * filtering are the SchemaExporter's job, applied before the tables arrive here,
 * so a generator never sorts and two runs on the same input are byte-identical.
 *
 * Structure only, never row data. Every generator operates solely on table,
 * column, index, and foreign-key structure.
 */
interface Generator
{
    /**
     * @param  list<array<string, mixed>>  $tables
     */
    public function generate(array $tables): string;
}
