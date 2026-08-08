<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Export;

use AlbertoArena\Truss\Export\Contracts\Generator;

/**
 * The whole schema as a JSON array of tables, in the exact SchemaSerializer
 * shape (name / columns / primary_key / indexes / foreign_keys). Structure only.
 *
 * Unlike DBML, Markdown, and Mermaid, this has no per-table browser counterpart
 * to reconcile against: the browser JSON export dumps a single table, whereas
 * the CLI needs the whole snapshot. It is a first-class server shape, pinned by
 * its own golden test rather than the JS cross-check.
 */
class JsonGenerator implements Generator
{
    public function generate(array $tables, array $notes = []): string
    {
        // Per-table and per-column annotations ride along as `annotation` keys
        // already present on the arrays; the bare-array shape is preserved, so an
        // un-annotated snapshot encodes identically. Global notes are not added
        // here: that would reshape the top level away from a plain table array.
        return (string) json_encode(
            array_values($tables),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
