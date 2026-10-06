<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Cli\Commands;

use AlbertoArena\Truss\Cli\Bootstrapper;
use AlbertoArena\Truss\Cli\Dsn;
use AlbertoArena\Truss\Cli\InvalidDsn;
use Illuminate\Container\Container;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Everything every command does before it can do its own work: find a
 * connection string, turn it into a connection, boot the container, and refuse
 * to continue if the database is not there.
 *
 * **The environment comes first and `--dsn` is the convenience**, not the other
 * way round. A connection string on the command line lands in shell history and
 * is visible in `ps` to every other user on the machine, so the documented path
 * is `TRUSS_DSN`, and the flag exists because sometimes you are typing one
 * command against one database and know what you are doing.
 *
 * **Reachability is checked here rather than left to the package.**
 * `SnapshotBuilder` answers an unreachable connection by replaying the
 * application's migrations on in-memory SQLite, which is the right answer
 * inside Laravel and impossible here: there is no application, so entering that
 * path resolves `migrator` out of a container that has none. An unreachable
 * database is also the most likely first mistake anybody makes with this
 * binary, a wrong host or a closed port or a forgotten VPN, so it gets a
 * sentence naming the driver and the host, and never the credentials.
 */
abstract class ConnectionCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'dsn',
            null,
            InputOption::VALUE_REQUIRED,
            'Connection string, for example pgsql://user:pass@host/db. Prefer TRUSS_DSN: an argument is visible in ps and in shell history',
        );
    }

    /**
     * The command's own work, with a booted container and a live connection.
     */
    abstract protected function handle(InputInterface $input, OutputInterface $output, Container $container): int;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $this->errorOutput($output);

        $dsn = $this->dsn($input);

        if ($dsn === '') {
            $errors->writeln('<error>No database to read.</error> Set TRUSS_DSN, or pass --dsn=pgsql://user:pass@host/db.');

            return self::FAILURE;
        }

        try {
            $connection = Dsn::parse($dsn);
        } catch (InvalidDsn $e) {
            $errors->writeln('<error>'.$e->getMessage().'</error>');

            return self::FAILURE;
        }

        $container = Bootstrapper::boot($connection);

        if (! $this->isReachable($container)) {
            $errors->writeln('<error>Truss could not reach the database:</error> '.Dsn::describe($connection).'.');
            $errors->writeln('Check the host, the port, and whether this machine needs a VPN or an allow-listed address.');

            return self::FAILURE;
        }

        return $this->handle($input, $output, $container);
    }

    private function dsn(InputInterface $input): string
    {
        $option = $input->getOption('dsn');

        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        $environment = getenv('TRUSS_DSN');

        return is_string($environment) ? trim($environment) : '';
    }

    /**
     * Whether the connection can actually be opened, asked exactly as the
     * package asks it, so the two agree about what "reachable" means.
     */
    private function isReachable(Container $container): bool
    {
        try {
            $container->make('db')->connection(Bootstrapper::CONNECTION)->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Errors go to stderr so that `truss export > schema.json` keeps the file
     * clean, and so a CI log still carries the reason. A plain output (which is
     * what a test harness gives) has no error stream, and then this is it.
     */
    private function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
