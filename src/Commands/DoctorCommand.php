<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Commands;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Doctor\Contracts\Formatter;
use AlbertoArena\Truss\Doctor\DoctorReport;
use AlbertoArena\Truss\Doctor\FindingCollection;
use AlbertoArena\Truss\Doctor\Formatters\ConsoleFormatter;
use AlbertoArena\Truss\Doctor\Formatters\JsonFormatter;
use AlbertoArena\Truss\Doctor\Severity;
use Illuminate\Console\Command;
use Throwable;

/**
 * Review the database structure for problems visible from structure alone, and
 * fail the build when one is found. Structure only, never row data, and no
 * network call, so it is safe in CI and commit hooks.
 *
 * Exit codes: 0 clean (below the fail level), 1 findings at or above it, 2 a
 * configuration, connection, or snapshot error.
 */
class DoctorCommand extends Command
{
    protected $signature = 'truss:doctor
        {--connection= : Review this connection instead of the default}
        {--table= : Review only this table}
        {--only= : Only these categories, comma-separated (integrity,index,type)}
        {--skip= : Skip these categories, comma-separated}
        {--preset= : recommended, strict, or none (defaults to config)}
        {--format=console : console or json}
        {--fail-on= : error, warning, info, or never (defaults to config)}';

    protected $description = 'Review the database structure for problems (structure only)';

    public function __construct()
    {
        parent::__construct();

        $this->setAliases(['truss:check']);
    }

    public function handle(SchemaCacheRepository $cache): int
    {
        $format = (string) $this->option('format');
        $preset = $this->option('preset') ?: (string) config('truss.doctor.preset', 'recommended');
        $failOn = $this->option('fail-on') ?: (string) config('truss.doctor.fail_on', 'error');

        if (! $this->valid($format, $preset, $failOn)) {
            $this->error('Invalid --format, --preset, or --fail-on value.');

            return 2;
        }

        try {
            $snapshot = $cache->get($this->option('connection') ? (string) $this->option('connection') : null);
        } catch (Throwable $e) {
            $this->error("Could not load the schema: {$e->getMessage()}");

            return 2;
        }

        $findings = (new DoctorReport)->for(
            $snapshot['connection'],
            $snapshot,
            $preset,
            $this->categories('only'),
            $this->categories('skip'),
            $this->option('table') !== null ? (string) $this->option('table') : null,
        );

        foreach (explode("\n", rtrim($this->formatterFor($format)->format($findings), "\n")) as $line) {
            $this->line($line);
        }

        return $this->exitCode($findings, $failOn);
    }

    private function valid(string $format, string $preset, string $failOn): bool
    {
        return in_array($format, ['console', 'json'], true)
            && in_array($preset, ['recommended', 'strict', 'none'], true)
            && in_array($failOn, ['error', 'warning', 'info', 'never'], true);
    }

    /**
     * @return list<string>
     */
    private function categories(string $option): array
    {
        $value = (string) ($this->option($option) ?? '');

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private function formatterFor(string $format): Formatter
    {
        return $format === 'json' ? new JsonFormatter : new ConsoleFormatter;
    }

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
}
