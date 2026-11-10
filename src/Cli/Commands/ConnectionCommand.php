<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Cli\Commands;

use AlbertoArena\Truss\Cli\Bootstrapper;
use AlbertoArena\Truss\Cli\ConnectionFailure;
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
 * **Every failure here exits 2, never 1.** That is the one thing in this class
 * worth stating twice: 1 is reserved for a verdict about the schema, which is
 * doctor findings at or above the fail level and an export that has drifted.
 * A CI job reading 1 from `truss doctor` concludes the structure has errors, so
 * an unreachable host returning 1 would report a schema problem that does not
 * exist and hide an infrastructure one that does. The artisan commands already
 * draw this line, where 2 is a configuration, connection or snapshot error.
 *
 * **Reachability is checked here rather than left to the package.**
 * `SnapshotBuilder` answers an unreachable connection by replaying the
 * application's migrations on in-memory SQLite, which is the right answer
 * inside Laravel and impossible here: there is no application, so entering that
 * path resolves `migrator` out of a container that has none. An unreachable
 * database is also the most likely first mistake anybody makes with this
 * binary, a wrong host or a closed port or a forgotten VPN, so it gets a
 * sentence naming the driver and the host, and never the credentials.
 *
 * **A server that answered is not unreachable**, and says so: one that refused
 * the login, or has no database by that name. Nor is a SQLite file that is not
 * there. Sending somebody with a mistyped password, database name or path off
 * to check the VPN is the wrong turn this message used to cause. See
 * `ConnectionFailure`.
 */
abstract class ConnectionCommand extends Command
{
    /**
     * Every connection this invocation was given, so that anything repeated
     * from an exception can have each password taken out of it.
     *
     * @var list<array<string, mixed>>
     */
    private array $connections = [];

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

            return self::INVALID;
        }

        try {
            $connection = Dsn::parse($dsn);
        } catch (InvalidDsn $e) {
            $errors->writeln('<error>'.$e->getMessage().'</error>');

            return self::INVALID;
        }

        $container = Bootstrapper::boot($connection);

        if (! $this->connects($output, $container, Bootstrapper::CONNECTION, $connection, 'the database')) {
            return self::INVALID;
        }

        return $this->handle($input, $output, $container);
    }

    /**
     * Open a registered connection, or say why it would not open and return
     * false. `$what` names it in the sentence, for a command with two.
     *
     * Asked exactly as the package asks it, so the two agree about what
     * "reachable" means.
     *
     * @param  array<string, mixed>  $connection
     */
    protected function connects(OutputInterface $output, Container $container, string $name, array $connection, string $what): bool
    {
        if (! in_array($connection, $this->connections, true)) {
            $this->connections[] = $connection;
        }

        if ($this->sqliteFileMissing($connection)) {
            $this->errorOutput($output)->writeln("<error>There is no SQLite file for {$what}:</error> ".Dsn::describe($connection).'.');
            $this->errorOutput($output)->writeln('Check the path in the connection string.');

            return false;
        }

        try {
            $container->make('db')->connection($name)->getPdo();

            return true;
        } catch (Throwable $e) {
            $errors = $this->errorOutput($output);

            if (ConnectionFailure::refusedCredentials($e)) {
                $errors->writeln('<error>'.ucfirst($what).' refused the user name or password:</error> '.Dsn::describe($connection).'.');
                $errors->writeln('The server answered, so this is not a network problem. Check the credentials in the connection string.');
            } elseif (ConnectionFailure::unknownDatabase($e)) {
                $errors->writeln('<error>'.ucfirst($what).' does not exist on its server:</error> '.Dsn::describe($connection).'.');
                $errors->writeln('The server answered, so this is not a network problem. Check the database name in the connection string.');
            } else {
                $errors->writeln("<error>Truss could not reach {$what}:</error> ".Dsn::describe($connection).'.');
                $errors->writeln('Check the host, the port, and whether this machine needs a VPN or an allow-listed address.');
            }

            return false;
        }
    }

    /**
     * Report a snapshot that could not be built, with every password this
     * invocation was given taken out of the exception's message, and exit 2.
     */
    protected function schemaLoadFailed(OutputInterface $output, Throwable $e): int
    {
        $message = $e->getMessage();

        foreach ($this->connections as $connection) {
            $message = Dsn::redact($message, $connection);
        }

        $this->errorOutput($output)->writeln("<error>Could not load the schema:</error> {$message}");

        return self::INVALID;
    }

    /**
     * A SQLite connection naming a file that is not there.
     *
     * Checked here rather than left to the connector, which looks the path up
     * against `base_path()` before saying the file is missing. The binary has no
     * application and so no base path: on the bundled Illuminate the call fails
     * with an Error, and the file is reported as unreachable. In-memory forms
     * are the ones the connector itself lets through.
     *
     * @param  array<string, mixed>  $connection
     */
    private function sqliteFileMissing(array $connection): bool
    {
        if (($connection['driver'] ?? null) !== 'sqlite') {
            return false;
        }

        $path = (string) ($connection['database'] ?? '');

        if ($path === ':memory:' || str_contains($path, '?mode=memory') || str_contains($path, '&mode=memory')) {
            return false;
        }

        return ! file_exists($path);
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
     * Errors go to stderr so that `truss export > schema.json` keeps the file
     * clean, and so a CI log still carries the reason. A plain output (which is
     * what a test harness gives) has no error stream, and then this is it.
     */
    private function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
