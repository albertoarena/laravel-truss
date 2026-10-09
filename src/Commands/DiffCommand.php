<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Commands;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Commands\Concerns\WarnsWhenUncached;
use AlbertoArena\Truss\Diff\BaselineStore;
use AlbertoArena\Truss\Diff\DiffRenderer;
use AlbertoArena\Truss\Diff\SchemaDiffer;
use Illuminate\Console\Command;

/**
 * Print "what changed since the last migration" as a terminal diff: the text
 * counterpart to the dashboard "Changes" panel. Compares the recorded baseline
 * (the schema before the last migration) against the current cached snapshot.
 * Structure only, never row data, so it is safe in CI and commit hooks.
 */
class DiffCommand extends Command
{
    use WarnsWhenUncached;

    protected $signature = 'truss:diff {--connection= : Diff this connection instead of the default}';

    protected $description = 'Show what changed in the database structure since the last migration';

    public function handle(SchemaCacheRepository $cache, BaselineStore $baselines, SchemaDiffer $differ): int
    {
        if (! config('truss.diff.enabled', true)) {
            $this->warn('Schema diff is disabled. Set truss.diff.enabled to true to record a baseline.');

            return self::SUCCESS;
        }

        $connection = $this->option('connection') ? (string) $this->option('connection') : null;
        $current = $cache->get($connection);
        $this->warnIfUncached($cache);
        $name = $current['connection'];

        $baseline = $baselines->get($name);

        if ($baseline === null) {
            // A read failure and an absent baseline both arrive as null, and they
            // need different advice: one is the normal state of a fresh install,
            // the other is a misconfigured disk the user cannot guess at from
            // "no baseline recorded".
            $error = $baselines->lastError();

            if ($error !== null) {
                $disk = (string) config('truss.diff.disk', 'local');
                $this->warn("Could not read the diff baseline from the [{$disk}] disk.");
                $this->line("  {$error}");
                $this->line('Set TRUSS_DIFF_DISK to a local disk, or disable the feature with TRUSS_DIFF_ENABLED=false.');
                $this->line('See https://trussphp.com/guides/schema-diff/');

                return self::SUCCESS;
            }

            $this->warn("No baseline recorded for [{$name}] yet.");
            $this->line('A baseline is captured on the next migration while Truss is enabled.');

            return self::SUCCESS;
        }

        $diff = $differ->diff($baseline, $current);

        if (! $diff['has_changes']) {
            $this->info("No structural changes on [{$name}] since the last migration.");

            return self::SUCCESS;
        }

        $this->line("Schema changes on <info>{$name}</info> since the last migration:");

        // The headline above is this command's own, because it answers "since
        // the last migration". The body is shared with the binary's two-DSN
        // diff, which asks a different question about the same shapes.
        foreach (DiffRenderer::body($diff) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
