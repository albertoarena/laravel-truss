<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Cli\Commands;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Export\SchemaExporter;
use AlbertoArena\Truss\TrussManager;
use Illuminate\Container\Container;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Export the database structure for CI, tooling and version control, from
 * outside any application.
 *
 * This is the command with a contract rather than a presentation. Its bytes get
 * committed to repositories and compared by `--check`, so they belong to the
 * format and not to the surface that printed them: the binary and
 * `php artisan truss:export` must produce the same file for the same schema,
 * which is what the parity test asserts.
 *
 * **The exit codes are the artisan command's**, because a CI job cannot tell
 * which surface it ran: 0 written or up to date, 1 drift found by `--check`, 2
 * a usage or runtime error. Symfony's `Command::INVALID` is 2, so both surfaces
 * agree without either hardcoding the other's numbers.
 *
 * **There is no `--connection`.** The artisan command picks among the
 * connections an application configures; here the DSN is that choice, and a
 * second way to make it would be a way to disagree with it.
 */
#[AsCommand(name: 'export', description: 'Export the database structure for CI and tooling (structure only)')]
final class ExportCommand extends ConnectionCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'dbml, json, csv, markdown, mermaid, llm or html (default: truss.export.default_format)')
            ->addOption('tables', null, InputOption::VALUE_REQUIRED, 'Only these tables, comma-separated')
            ->addOption('exclude', null, InputOption::VALUE_REQUIRED, 'Skip these tables, comma-separated (applied after --tables)')
            ->addOption('focus', null, InputOption::VALUE_REQUIRED, 'Reduce to this table and its foreign-key neighbourhood')
            ->addOption('depth', null, InputOption::VALUE_REQUIRED, 'Neighbourhood hops for --focus (default: truss.focus.default_depth)')
            ->addOption('compact', null, InputOption::VALUE_NONE, 'Drop defaults and non-unique indexes to shrink the output')
            ->addOption('no-annotations', null, InputOption::VALUE_NONE, 'Strip config and database annotations from the export')
            ->addOption('mermaid', null, InputOption::VALUE_REQUIRED, 'html only: inline (self-contained, default) or cdn (small file, needs a network)', 'inline')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Write to this file instead of stdout')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Exit non-zero if --output would change; writes nothing');
    }

    protected function handle(InputInterface $input, OutputInterface $output, Container $container): int
    {
        $errors = $this->errors($output);

        $format = $this->string($input, 'format') ?? (string) config('truss.export.default_format', 'dbml');

        if (! SchemaExporter::supports($format)) {
            $errors->writeln("<error>Unknown --format [{$format}].</error> Supported: ".implode(', ', SchemaExporter::formats()).'.');

            return self::INVALID;
        }

        $file = $this->string($input, 'output');
        $check = (bool) $input->getOption('check');

        if ($check && $file === null) {
            $errors->writeln('<error>--check requires --output:</error> there is nothing to compare against.');

            return self::INVALID;
        }

        $mermaid = $this->string($input, 'mermaid') ?? 'inline';

        if (! in_array($mermaid, ['inline', 'cdn'], true)) {
            $errors->writeln("<error>Unknown --mermaid [{$mermaid}].</error> Supported: inline, cdn.");

            return self::INVALID;
        }

        // An HTML export is a document of roughly 3.6 MB, almost all of it
        // minified Mermaid, so a forgotten redirect fills a terminal or a CI
        // log with no way back. The six text formats are small, pipe cleanly,
        // and keep stdout.
        if ($format === 'html' && $file === null) {
            $errors->writeln('<error>--format=html requires --output:</error> the file is around 3.6 MB and is not meant for a terminal.');
            $errors->writeln('');
            $errors->writeln('  Try: <comment>truss export --format=html --output=schema.html</comment>');

            return self::INVALID;
        }

        $builder = $container->make(TrussManager::class)->snapshot()
            ->only($this->list($input, 'tables'))
            ->except($this->list($input, 'exclude'));

        if ($focus = $this->string($input, 'focus')) {
            $depth = $this->string($input, 'depth');
            $builder = $builder->focus($focus, $depth === null ? null : (int) $depth);
        }

        if ($input->getOption('compact')) {
            $builder = $builder->compact();
        }

        if ($input->getOption('no-annotations')) {
            $builder = $builder->withoutAnnotations();
        }

        if ($mermaid === 'cdn') {
            $builder = $builder->mermaidFromUrl();
        }

        try {
            $tables = $builder->toArray();
        } catch (InvalidArgumentException $e) {
            // A missing focus table. The connection is already known good: the
            // base command refused to get this far without one.
            $errors->writeln('<error>'.$e->getMessage().'</error>');

            return self::INVALID;
        } catch (Throwable $e) {
            $errors->writeln("<error>Could not load the schema:</error> {$e->getMessage()}");

            return self::INVALID;
        }

        if ($tables === []) {
            $errors->writeln('<error>No tables matched the given filters.</error>');

            return self::INVALID;
        }

        // There is no --fresh here and no notice about a cold cache, because
        // the CLI's cache is an array store that dies with the process: every
        // run is already fresh, so the flag would do nothing and the notice
        // would fire every time. The cache error is still worth reporting,
        // since a store that cannot be written is a real condition in a
        // container that configures one.
        $cacheError = $container->make(SchemaCacheRepository::class)->lastError();

        if ($cacheError !== null) {
            $errors->writeln("<comment>The cache store is unavailable, so the schema was read live: {$cacheError}</comment>");
        }

        $content = $builder->render($format);

        if ($check) {
            return $this->check($errors, $file, $content);
        }

        if ($file !== null) {
            return $this->write($errors, $file, $content, $format);
        }

        // write() rather than writeln(): the generators end their output with
        // exactly one newline, and a second one would change the bytes of
        // anything piped into a file.
        $output->write($content);

        return self::SUCCESS;
    }

    /**
     * Compare freshly generated output against the file, writing nothing. 0
     * when they match, 1 when the file is missing or would change.
     */
    private function check(OutputInterface $errors, string $file, string $content): int
    {
        if ((is_file($file) ? file_get_contents($file) : null) === $content) {
            $errors->writeln("<info>{$file} is up to date.</info>");

            return self::SUCCESS;
        }

        $errors->writeln("<error>{$file} is out of date.</error> Regenerate it with truss export --output={$file}.");

        return self::FAILURE;
    }

    private function write(OutputInterface $errors, string $file, string $content, string $format): int
    {
        if (@file_put_contents($file, $content) === false) {
            $errors->writeln("<error>Could not write to [{$file}].</error>");

            return self::INVALID;
        }

        $errors->writeln("<info>Wrote {$format} export to {$file}.</info>");

        return self::SUCCESS;
    }

    private function string(InputInterface $input, string $option): ?string
    {
        $value = $input->getOption($option);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return list<string>
     */
    private function list(InputInterface $input, string $option): array
    {
        $value = $this->string($input, $option) ?? '';

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
    }

    /**
     * Everything that is not the export goes to stderr, including the "wrote
     * it" confirmation: without --output the export itself is on stdout and
     * being piped, so a notice there corrupts the artifact.
     */
    private function errors(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
