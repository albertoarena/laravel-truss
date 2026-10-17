<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Cli\Commands;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use AlbertoArena\Truss\Cli\Bootstrapper;
use AlbertoArena\Truss\Cli\Dsn;
use AlbertoArena\Truss\Cli\InvalidDsn;
use AlbertoArena\Truss\Diff\DiffRenderer;
use AlbertoArena\Truss\Diff\SchemaDiffer;
use Illuminate\Container\Container;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * What differs between two live databases, right now.
 *
 * **This is the one command that does something the package cannot.** Inside an
 * application, `truss:diff` compares the current schema against the baseline
 * recorded before the last migration, which is a question about time. Given two
 * connection strings it becomes a question about place: what differs between
 * staging and production. That question gets asked often and is awkward to
 * answer any other way.
 *
 * **No baseline is involved, and none could be.** A baseline is written by the
 * migration listener; there are no migrations here and both sides are live.
 * That is why the binary needs no filesystem: `BaselineStore` is the Laravel
 * path's way of fetching the left-hand array, and `SchemaDiffer::diff()` takes
 * two arrays and nothing else.
 *
 * **Direction, because it is the classic bug in a two-sided diff.** `--dsn` is
 * the current state and `--against` is what it is compared to, so a table only
 * `--dsn` has reads as added, and one only `--against` has reads as removed.
 *
 * The body renders through the shared `DiffRenderer`, so it is identical to the
 * artisan command's. Only the headline differs, and it should: the two commands
 * answer different questions.
 */
#[AsCommand(name: 'diff', description: 'Show what differs between two live databases (structure only)')]
final class DiffCommand extends ConnectionCommand
{
    /** The connection name the right-hand side is registered under. */
    private const AGAINST = 'truss_against';

    protected function configure(): void
    {
        parent::configure();

        $this->addOption(
            'against',
            null,
            InputOption::VALUE_REQUIRED,
            'The database to compare against, as a second connection string. --dsn is the current state; this is the baseline. Prefer TRUSS_AGAINST_DSN: an argument is visible in ps and in shell history',
        );
    }

    protected function handle(InputInterface $input, OutputInterface $output, Container $container): int
    {
        $errors = $this->errors($output);

        $raw = $this->against($input);

        if ($raw === '') {
            $errors->writeln('<error>Nothing to compare against.</error> Set TRUSS_AGAINST_DSN, or pass a second connection string with --against.');
            $errors->writeln('');
            $errors->writeln('  Try: <comment>TRUSS_DSN=$STAGING_DSN TRUSS_AGAINST_DSN=$PRODUCTION_DSN truss diff</comment>');

            return self::INVALID;
        }

        try {
            $against = Dsn::parse($raw);
        } catch (InvalidDsn $e) {
            $errors->writeln('<error>'.$e->getMessage().'</error>');

            return self::INVALID;
        }

        Bootstrapper::registerConnection($container, self::AGAINST, $against);

        if (! $this->isReachable($container, self::AGAINST)) {
            $errors->writeln('<error>Truss could not reach the database to compare against:</error> '.Dsn::describe($against).'.');
            $errors->writeln('Check the host, the port, and whether this machine needs a VPN or an allow-listed address.');

            return self::INVALID;
        }

        $cache = $container->make(SchemaCacheRepository::class);

        try {
            $current = $cache->get();
            $baseline = $cache->get(self::AGAINST);
        } catch (Throwable $e) {
            $errors->writeln("<error>Could not load the schema:</error> {$e->getMessage()}");

            return self::INVALID;
        }

        $connection = $this->connectionOf($container, Bootstrapper::CONNECTION);
        $here = Dsn::describe($connection);
        $there = Dsn::describe($against);

        $diff = SchemaDiffer::forDrivers((string) ($connection['driver'] ?? ''), (string) ($against['driver'] ?? ''))
            ->diff($baseline, $current);

        if (! $diff['has_changes']) {
            $output->writeln("<info>No structural differences</info> between {$here} and {$there}.");

            return self::SUCCESS;
        }

        // Both sides named, and neither set of credentials: describe() is the
        // redacted form, and this line ends up in terminals and CI logs.
        $output->writeln("Structural differences between {$here} and {$there}:");

        foreach (DiffRenderer::body($diff) as $line) {
            $output->writeln($line);
        }

        return self::SUCCESS;
    }

    /**
     * The second connection string, read the way the first one is.
     *
     * It is a credential exactly like `--dsn`, so it gets the same environment
     * path: an argument is visible in `ps` and in shell history. The option
     * wins when both are set, as `--dsn` wins over `TRUSS_DSN`.
     */
    private function against(InputInterface $input): string
    {
        $option = $input->getOption('against');

        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        $environment = getenv('TRUSS_AGAINST_DSN');

        return is_string($environment) ? trim($environment) : '';
    }

    /**
     * The config of a registered connection, so the headline can describe the
     * left-hand side without the command having to carry it down from the base.
     *
     * @return array<string, mixed>
     */
    private function connectionOf(Container $container, string $name): array
    {
        return (array) $container->make('config')->get("database.connections.{$name}", []);
    }

    private function isReachable(Container $container, string $name): bool
    {
        try {
            $container->make('db')->connection($name)->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function errors(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
