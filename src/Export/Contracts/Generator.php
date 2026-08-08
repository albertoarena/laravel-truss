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
     * @param  list<array<string, mixed>>  $tables  each may carry an optional
     *                                              `annotation` string, and each
     *                                              column an optional `annotation`
     * @param  list<string>  $notes  global notes, rendered in a header block by the
     *                               formats that have one (empty means none, so the
     *                               output is unchanged from an un-annotated export)
     */
    public function generate(array $tables, array $notes = []): string;
}
