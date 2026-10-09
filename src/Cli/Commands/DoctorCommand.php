<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Cli\Commands;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Doctor\Contracts\Formatter;
use AlbertoArena\Truss\Doctor\DoctorReport;
use AlbertoArena\Truss\Doctor\FindingCollection;
use AlbertoArena\Truss\Doctor\Formatters\ConsoleFormatter;
use AlbertoArena\Truss\Doctor\Formatters\JsonFormatter;
use AlbertoArena\Truss\Doctor\Severity;
use Illuminate\Container\Container;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Review a database's structure for problems visible from the structure alone,
 * from outside any application.
 *
 * **The exit codes are the artisan command's**, and here that matters more than
 * anywhere else in the binary: the thing reading them is a CI job that cannot
 * tell which surface ran. 0 clean, 1 findings at or above the fail level, 2 a
 * bad option or a schema that could not be read.
 *
 * **It renders through the same formatters**, which is what makes the console
 * report byte-identical across the two surfaces: `ConsoleFormatter` builds the
 * whole report into its own buffer and emits no colour, so neither command
 * contributes anything of its own to the output.
 *
 * It keeps the `check` alias the artisan command carries, so muscle memory and
 * documentation both carry across.
 */
#[AsCommand(name: 'doctor', description: 'Review the database structure for problems (structure only)', aliases: ['check'])]
final class DoctorCommand extends ConnectionCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('table', null, InputOption::VALUE_REQUIRED, 'Review only this table')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Only these categories, comma-separated (integrity,index,type)')
            ->addOption('skip', null, InputOption::VALUE_REQUIRED, 'Skip these categories, comma-separated')
            ->addOption('preset', null, InputOption::VALUE_REQUIRED, 'recommended, strict, or none (defaults to config)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'console or json', 'console')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'error, warning, info, or never (defaults to config)');
    }

    protected function handle(InputInterface $input, OutputInterface $output, Container $container): int
    {
        $errors = $this->errors($output);

        $format = (string) ($input->getOption('format') ?? 'console');
        $preset = $this->string($input, 'preset') ?? (string) config('truss.doctor.preset', 'recommended');
        $failOn = $this->string($input, 'fail-on') ?? (string) config('truss.doctor.fail_on', 'error');

        if (! $this->valid($format, $preset, $failOn)) {
            $errors->writeln('<error>Invalid --format, --preset, or --fail-on value.</error>');

            return self::INVALID;
        }

        try {
            $snapshot = $container->make(SchemaCacheRepository::class)->get();
        } catch (Throwable $e) {
            // The connection is already known good: the base command refused to
            // get this far without one. So this is a snapshot failure, which is
            // a different thing from being unable to connect.
            $errors->writeln("<error>Could not load the schema:</error> {$e->getMessage()}");

            return self::INVALID;
        }

        $findings = (new DoctorReport)->for(
            (string) $snapshot['connection'],
            $snapshot,
            $preset,
            $this->list($input, 'only'),
            $this->list($input, 'skip'),
            $this->string($input, 'table'),
        );

        // Line by line, as the artisan command does, rather than one write of
        // the whole buffer: the formatter's trailing newline is trimmed and the
        // console adds its own, which is what keeps the two outputs equal.
        foreach (explode("\n", rtrim($this->formatterFor($format)->format($findings), "\n")) as $line) {
            $output->writeln($line);
        }

        return $this->exitCode($findings, $failOn);
    }

    private function valid(string $format, string $preset, string $failOn): bool
    {
        return in_array($format, ['console', 'json'], true)
            && in_array($preset, ['recommended', 'strict', 'none'], true)
            && in_array($failOn, ['error', 'warning', 'info', 'never'], true);
    }

    private function formatterFor(string $format): Formatter
    {
        return $format === 'json' ? new JsonFormatter : new ConsoleFormatter;
    }

    /**
     * `--fail-on` is a floor rather than an equality test: a warning threshold
     * still fails on an error.
     */
    private function exitCode(FindingCollection $findings, string $failOn): int
    {
        if ($failOn === 'never') {
            return self::SUCCESS;
        }

        $threshold = Severity::from($failOn);

        foreach ($findings as $finding) {
            if ($finding->severity->meetsOrExceeds($threshold)) {
                return self::FAILURE;
            }
        }

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
     * The report goes to stdout so `truss doctor --format=json | jq` works;
     * only the refusals go to stderr.
     */
    private function errors(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
