<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Export;

/**
 * Resolves the merged annotation set for a snapshot and enriches its tables with
 * the resolved text. Annotations are business meaning that cannot be
 * introspected (that status = 1 means paid, that a table is deprecated); they
 * come from config and/or native schema comments, with a configurable
 * precedence.
 *
 * Pure over its inputs: it is given the config maps and a comment map (the only
 * DB-touching part, a CommentReader, is resolved and injected by the caller so
 * it can be faked in tests). It never reads row data, only structure and the
 * declared meaning of that structure.
 *
 * Enrichment is additive and conditional: a table or column with no resolved
 * annotation is returned untouched (no `annotation` key), so an un-annotated
 * export is byte-identical to one produced without an Annotator at all.
 */
final class Annotator
{
    /**
     * @param  list<string>  $source  ordered precedence, e.g. ['config', 'database']
     * @param  array<string, string>  $tableNotes  config table annotations, keyed by table name
     * @param  array<string, string>  $columnNotes  config column annotations, keyed by "table.column"
     * @param  array<string, string>  $comments  native DB comments, keyed by table name and "table.column"
     * @param  list<string>  $notes  global notes
     */
    public function __construct(
        private readonly array $source = ['config'],
        private readonly array $tableNotes = [],
        private readonly array $columnNotes = [],
        private readonly array $comments = [],
        private readonly array $notes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $config  the truss.annotations config array
     * @param  array<string, string>  $comments  native comments from a CommentReader
     */
    public static function fromConfig(array $config, array $comments = []): self
    {
        return new self(
            source: array_values((array) ($config['source'] ?? ['config'])),
            tableNotes: (array) ($config['tables'] ?? []),
            columnNotes: (array) ($config['columns'] ?? []),
            comments: $comments,
            notes: array_values((array) ($config['notes'] ?? [])),
        );
    }

    /**
     * Resolve the annotation for a table name or a "table.column" key by walking
     * the sources in order; the first non-empty value wins. Null when none.
     */
    public function for(string $key): ?string
    {
        $isColumn = str_contains($key, '.');

        foreach ($this->source as $source) {
            $value = match ($source) {
                'config' => $isColumn ? ($this->columnNotes[$key] ?? null) : ($this->tableNotes[$key] ?? null),
                'database' => $this->comments[$key] ?? null,
                default => null,
            };

            if ($value !== null && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * The trimmed, non-empty global notes, in order.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return array_values(array_filter(
            array_map(fn (string $note): string => trim($note), $this->notes),
            fn (string $note): bool => $note !== '',
        ));
    }

    /**
     * Enrich the tables with their resolved annotations. Only non-empty
     * annotations are attached; a table or column keyed to a name that no longer
     * exists is simply never matched, never an error.
     *
     * @param  list<array<string, mixed>>  $tables
     * @return list<array<string, mixed>>
     */
    public function annotate(array $tables): array
    {
        return array_map(function (array $table): array {
            $name = $table['name'];

            $tableNote = $this->for($name);
            if ($tableNote !== null) {
                $table['annotation'] = $tableNote;
            }

            $table['columns'] = array_map(function (array $column) use ($name): array {
                $columnNote = $this->for("{$name}.{$column['name']}");
                if ($columnNote !== null) {
                    $column['annotation'] = $columnNote;
                }

                return $column;
            }, $table['columns'] ?? []);

            return $table;
        }, $tables);
    }
}
