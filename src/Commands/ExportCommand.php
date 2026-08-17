<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Commands;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Export\SchemaExporter;
use AlbertoArena\Truss\TrussManager;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Export the database structure to a standard format (DBML, JSON, CSV, Markdown,
 * or Mermaid) for CI, tooling, and version control. Structure only, never row
 * data, and no network call, so it is safe in CI and commit hooks.
 *
 * Writes to stdout by default so it pipes cleanly; --output writes a file. The
 * output is deterministic: the same schema always produces the same bytes, which
 * is what lets --check fail a build when a committed export file has gone stale.
 *
 * Exit codes: 0 written or (with --check) up to date; 1 --check found drift; 2 a
 * usage or runtime error (unknown format, unmanaged/failed connection, unwritable
 * path, --check without --output, or no tables matched the filters).
 */
class ExportCommand extends Command
{
    protected $signature = 'truss:export
        {--format= : dbml, json, csv, markdown, mermaid, or llm (default: truss.export.default_format)}
        {--connection= : Export this connection instead of the default}
        {--tables= : Only these tables, comma-separated}
        {--exclude= : Skip these tables, comma-separated (applied after --tables)}
        {--focus= : Reduce to this table and its foreign-key neighbourhood}
        {--depth= : Neighbourhood hops for --focus (default: truss.focus.default_depth)}
        {--compact : Drop defaults and non-unique indexes to shrink the output}
        {--no-annotations : Strip config/database annotations from the export}
        {--output= : Write to this file instead of stdout}
        {--check : Exit non-zero if --output would change; writes nothing}
        {--fresh : Rebuild the cached snapshot before exporting}';

    protected $description = 'Export the database structure for CI and tooling (structure only)';

    public function handle(SchemaCacheRepository $cache, TrussManager $truss): int
    {
        $format = $this->option('format') !== null && $this->option('format') !== ''
            ? (string) $this->option('format')
            : (string) config('truss.export.default_format', 'dbml');
        if (! SchemaExporter::supports($format)) {
            $this->error("Unknown --format [{$format}]. Supported: ".implode(', ', SchemaExporter::formats()).'.');

            return 2;
        }

        $check = (bool) $this->option('check');
        $output = $this->option('output') !== null ? (string) $this->option('output') : null;
        if ($check && $output === null) {
            $this->error('--check requires --output: there is nothing to compare against.');

            return 2;
        }

        $connection = $this->option('connection') ? (string) $this->option('connection') : null;
        if ($connection !== null && ! in_array($connection, $cache->managedConnections(), true)) {
            $this->error("Connection [{$connection}] is not managed by Truss. Add it under truss.connections.");

            return 2;
        }

        // The command is a thin CLI wrapper over the shared export pipeline.
        $builder = $truss->snapshot()
            ->only($this->list('tables'))
            ->except($this->list('exclude'));
        if ($connection !== null) {
            $builder = $builder->connection($connection);
        }
        if ($this->option('focus')) {
            $depth = $this->option('depth') !== null ? (int) $this->option('depth') : null;
            $builder = $builder->focus((string) $this->option('focus'), $depth);
        }
        if ($this->option('compact')) {
            $builder = $builder->compact();
        }
        if ($this->option('no-annotations')) {
            $builder = $builder->withoutAnnotations();
        }
        if ($this->option('fresh')) {
            $builder = $builder->fresh();
        }

        try {
            $tables = $builder->toArray();
        } catch (InvalidArgumentException $e) {
            // A missing focus table (the connection is pre-validated above).
            $this->error($e->getMessage());

            return 2;
        } catch (Throwable $e) {
            $this->error("Could not load the schema: {$e->getMessage()}");

            return 2;
        }

        if ($tables === []) {
            $this->error('No tables matched the given filters.');

            return 2;
        }

        // Written to stderr, not stdout: without --output the export itself goes
        // to stdout and is piped, so a notice there would corrupt the artifact.
        // The exit code stays driven by drift alone, so an unrelated cache outage
        // can never turn a `--check` gate in CI red.
        $cacheError = $cache->lastError();

        if ($cacheError !== null) {
            // getErrorStyle() rather than getErrorOutput(): the latter is
            // protected on OutputStyle, so reaching for it is a fatal error the
            // moment the notice fires. It falls back to standard output when the
            // console has no separate error channel.
            $this->output->getErrorStyle()->writeln(
                "<comment>The cache store is unavailable, so the schema was read live: {$cacheError}</comment>"
            );
        }

        $content = $builder->render($format);

        if ($check) {
            return $this->check($output, $content);
        }

        if ($output !== null) {
            return $this->write($output, $content, $format);
        }

        $this->output->write($content);

        return self::SUCCESS;
    }

    /**
     * Compare freshly generated output against the file, writing nothing. Exit 0
     * when they match, 1 (drift) when the file is missing or would change.
     */
    private function check(string $output, string $content): int
    {
        $current = is_file($output) ? file_get_contents($output) : null;

        if ($current === $content) {
            $this->info("{$output} is up to date.");

            return self::SUCCESS;
        }

        $this->error("{$output} is out of date. Regenerate it with truss:export --output={$output}.");

        return 1;
    }

    private function write(string $output, string $content, string $format): int
    {
        if (@file_put_contents($output, $content) === false) {
            $this->error("Could not write to [{$output}].");

            return 2;
        }

        $this->info("Wrote {$format} export to {$output}.");

        return self::SUCCESS;
    }

    /**
     * Parse a comma-separated option into a trimmed, non-empty list.
     *
     * @return list<string>
     */
    private function list(string $option): array
    {
        $value = (string) ($this->option($option) ?? '');

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $v): bool => $v !== ''));
    }
}
