<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Export;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Doctor\DoctorReport;
use AlbertoArena\Truss\Export\Contracts\CommentReader;
use InvalidArgumentException;

/**
 * The canonical export pipeline as an immutable fluent builder: select a
 * connection and filters, then render one structural format. It is the single
 * source of truth that the `truss:export` command, the gated export route, and
 * the public `Truss` facade all drive, so every access path produces identical
 * output for the same inputs.
 *
 * Each filter returns a new instance (the base is never mutated), so a partially
 * configured builder can be shared and branched safely. Structure only: it reads
 * the same cached snapshot the dashboard uses and never touches row data. The
 * same safeguards apply on every path: config `excluded_tables` stripping, the
 * `managedConnections()` allow-list, and the connection's own exclusions.
 */
final class ExportBuilder
{
    private ?array $resolved = null;

    /**
     * @param  list<string>  $only
     * @param  list<string>  $except
     */
    public function __construct(
        private readonly SchemaCacheRepository $cache,
        private readonly SchemaExporter $exporter,
        private readonly CommentReader $commentReader,
        private readonly ?string $connection = null,
        private readonly array $only = [],
        private readonly array $except = [],
        private readonly ?string $focusTable = null,
        private readonly ?int $focusDepth = null,
        private readonly bool $compact = false,
        private readonly bool $annotations = true,
        private readonly bool $fresh = false,
        private readonly ?string $mermaidUrl = null,
    ) {}

    public function connection(string $name): self
    {
        return $this->copy(['connection' => $name]);
    }

    /**
     * Load Mermaid from a URL in the HTML export instead of embedding it.
     *
     * The default export is self-contained and around 3.6 MB, of which the
     * vendored Mermaid is roughly 97%. This trades that promise for a file small
     * enough to attach anywhere, at the cost of needing a network to open and of
     * rotting when the CDN's version moves. `truss.diagram.mermaid_url` is used
     * when it is set, since an install that already self-hosts Mermaid has said
     * where it lives.
     */
    public function mermaidFromUrl(?string $url = null): self
    {
        return $this->copy([
            'mermaidUrl' => $url
                ?? (string) (config('truss.diagram.mermaid_url')
                    ?: 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js'),
        ]);
    }

    /**
     * @param  list<string>  $tables
     */
    public function only(array $tables): self
    {
        return $this->copy(['only' => array_values($tables)]);
    }

    /**
     * @param  list<string>  $tables
     */
    public function except(array $tables): self
    {
        return $this->copy(['except' => array_values($tables)]);
    }

    public function focus(string $table, ?int $depth = null): self
    {
        return $this->copy(['focusTable' => $table, 'focusDepth' => $depth]);
    }

    public function compact(bool $compact = true): self
    {
        return $this->copy(['compact' => $compact]);
    }

    public function withoutAnnotations(): self
    {
        return $this->copy(['annotations' => false]);
    }

    public function fresh(bool $fresh = true): self
    {
        return $this->copy(['fresh' => $fresh]);
    }

    /**
     * The selected, filtered, focused, compacted, and annotated tables.
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return $this->resolve()['tables'];
    }

    /**
     * The resolved global notes (empty when annotations are off or none are set).
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->resolve()['notes'];
    }

    /**
     * Render the resolved tables in a structural format, normalised to exactly
     * one trailing newline so a file write, a piped stdout, and a `--check`
     * comparison all compare the same bytes.
     */
    public function render(string $format): string
    {
        $resolved = $this->resolve();

        return rtrim(
            $this->exporter->generate($format, $resolved['tables'], $resolved['notes'], $this->contextFor($format, $resolved)),
            "\n",
        )."\n";
    }

    /**
     * Constructor arguments for the generator, which only the HTML document
     * needs today.
     *
     * The format test is the one piece of format-awareness in this class, and it
     * sits here because this is the only place holding both the resolved
     * connection and the filtered tables. The doctor needs the first and must
     * run on the second, so a --tables or --focus export reports on what it
     * actually contains. Doing it inside the generator was the alternative and
     * it would have put a connection inside a generator documented as pure over
     * its tables.
     *
     * @param  array{tables: list<array<string, mixed>>, notes: list<string>, connection: string}  $resolved
     * @return array<string, mixed>
     */
    private function contextFor(string $format, array $resolved): array
    {
        if ($format !== 'html') {
            return [];
        }

        $doctor = new DoctorReport;

        return [
            'mermaidUrl' => $this->mermaidUrl,
            'doctor' => $doctor->toArray(
                $doctor->for($resolved['connection'], ['tables' => $resolved['tables']]),
            ),
        ];
    }

    public function toDbml(): string
    {
        return $this->render('dbml');
    }

    public function toJson(): string
    {
        return $this->render('json');
    }

    public function toCsv(): string
    {
        return $this->render('csv');
    }

    public function toMarkdown(): string
    {
        return $this->render('markdown');
    }

    public function toMermaid(): string
    {
        return $this->render('mermaid');
    }

    public function toLlm(): string
    {
        return $this->render('llm');
    }

    /**
     * Load the snapshot and run the full pipeline. Memoised, so several terminals
     * on the same instance resolve once.
     *
     * @return array{tables: list<array<string, mixed>>, notes: list<string>, connection: string}
     *
     * @throws InvalidArgumentException for an unmanaged connection or a missing focus table
     */
    private function resolve(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        if ($this->connection !== null && ! in_array($this->connection, $this->cache->managedConnections(), true)) {
            throw new InvalidArgumentException("Connection [{$this->connection}] is not managed by Truss. Add it under truss.connections.");
        }

        $snapshot = $this->fresh ? $this->cache->rebuild($this->connection) : $this->cache->get($this->connection);

        $tables = $this->exporter->tablesFor(
            $snapshot['tables'] ?? [],
            $this->only,
            $this->except,
            $this->excludedTablesFor((string) $snapshot['connection']),
        );

        if ($this->focusTable !== null) {
            $depth = $this->focusDepth ?? (int) config('truss.focus.default_depth', 1);
            $tables = (new FocusTransform)->apply($tables, $this->focusTable, $depth);
        }

        if ($this->compact) {
            $tables = (new CompactTransform)->apply($tables);
        }

        [$tables, $notes] = $this->applyAnnotations($tables, (string) $snapshot['connection']);

        return $this->resolved = [
            'tables' => $tables,
            'notes' => $notes,
            'connection' => (string) $snapshot['connection'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $tables
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function applyAnnotations(array $tables, string $connection): array
    {
        if (! $this->annotations) {
            return [$tables, []];
        }

        $config = (array) config('truss.annotations', []);
        $source = (array) ($config['source'] ?? ['config']);

        $comments = in_array('database', $source, true)
            ? $this->commentReader->read($connection)
            : [];

        $annotator = Annotator::fromConfig($config, $comments);

        return [$annotator->annotate($tables), $annotator->notes()];
    }

    /**
     * The global exclusion list merged with this connection's overrides, matching
     * what the schema endpoint strips server-side.
     *
     * @return list<string>
     */
    private function excludedTablesFor(string $connection): array
    {
        $global = (array) config('truss.excluded_tables', []);
        $perConnection = (array) config("truss.connections.{$connection}.excluded_tables", []);

        return array_values(array_unique([...$global, ...$perConnection]));
    }

    /**
     * @param  array<string, mixed>  $with
     */
    private function copy(array $with): self
    {
        return new self(
            $this->cache,
            $this->exporter,
            $this->commentReader,
            array_key_exists('connection', $with) ? $with['connection'] : $this->connection,
            array_key_exists('only', $with) ? $with['only'] : $this->only,
            array_key_exists('except', $with) ? $with['except'] : $this->except,
            array_key_exists('focusTable', $with) ? $with['focusTable'] : $this->focusTable,
            array_key_exists('focusDepth', $with) ? $with['focusDepth'] : $this->focusDepth,
            array_key_exists('compact', $with) ? $with['compact'] : $this->compact,
            array_key_exists('annotations', $with) ? $with['annotations'] : $this->annotations,
            array_key_exists('fresh', $with) ? $with['fresh'] : $this->fresh,
            array_key_exists('mermaidUrl', $with) ? $with['mermaidUrl'] : $this->mermaidUrl,
        );
    }
}
