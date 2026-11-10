<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cli\Commands\ConnectionCommand;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/*
 * Against a real server, because the whole question is what a real server says
 * when it is reached and says no: to the password, or to the database name. These run on the MySQL, MariaDB and
 * PostgreSQL CI lanes, which point DB_* at a service container with a
 * password, and skip on the default SQLite run, which has no server to refuse
 * anything. ConnectionFailureTest.php pins the same classification without a
 * server, so the SQLite run is not left with nothing.
 *
 * The one exception is a SQLite file that is not there, which needs no server
 * and so runs everywhere.
 */

afterEach(function (): void {
    Container::setInstance(null);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
    putenv('TRUSS_DSN');
    putenv('TRUSS_AGAINST_DSN');
});

/**
 * The CI lane's server as a connection string, with the password given (and
 * the database, when one is), or null on the SQLite run.
 */
function trussLaneDsn(string $password, ?string $database = null): ?string
{
    $driver = (string) (getenv('DB_CONNECTION') ?: 'sqlite');

    if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
        return null;
    }

    return sprintf(
        '%s://%s:%s@%s:%s/%s',
        $driver,
        rawurlencode((string) (getenv('DB_USERNAME') ?: 'root')),
        rawurlencode($password),
        (string) (getenv('DB_HOST') ?: '127.0.0.1'),
        (string) (getenv('DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306')),
        $database ?? (string) (getenv('DB_DATABASE') ?: 'truss_test'),
    );
}

function trussLaneDsnOrSkip(string $password, ?string $database = null): string
{
    $dsn = trussLaneDsn($password, $database);

    if ($dsn === null) {
        test()->markTestSkipped('Needs a database server; runs on the MySQL, MariaDB and PostgreSQL lanes.');
    }

    return $dsn;
}

it('says the server refused the credentials when the password is wrong', function (string $command): void {
    $tester = trussCliTester($command);
    $status = $tester->execute(['--dsn' => trussLaneDsnOrSkip('not-the-password-7f3a')]);
    $display = $tester->getDisplay();

    // Still 2: a refused login is not a verdict about the schema.
    expect($status)->toBe(2)
        ->and($display)->toContain('refused the user name or password')
        ->and($display)->not->toContain('could not reach')
        ->and($display)->not->toContain('VPN')
        ->and($display)->not->toContain('not-the-password-7f3a');
})->with(['show', 'doctor', 'export']);

it('says the database to compare against refused the credentials', function (): void {
    $tester = trussCliTester('diff');
    $status = $tester->execute([
        '--dsn' => trussLaneDsnOrSkip((string) getenv('DB_PASSWORD')),
        '--against' => trussLaneDsnOrSkip('not-the-password-7f3a'),
    ]);
    $display = $tester->getDisplay();

    expect($status)->toBe(2)
        ->and($display)->toContain('compare against refused the user name or password')
        ->and($display)->not->toContain('VPN')
        ->and($display)->not->toContain('not-the-password-7f3a');
});

it('says the database does not exist when the name is wrong', function (string $command): void {
    $tester = trussCliTester($command);
    $status = $tester->execute(['--dsn' => trussLaneDsnOrSkip((string) getenv('DB_PASSWORD'), 'no_such_database_7f3a')]);
    $display = $tester->getDisplay();

    expect($status)->toBe(2)
        ->and($display)->toContain('The database does not exist on its server')
        ->and($display)->toContain('no_such_database_7f3a')
        ->and($display)->toContain('Check the database name')
        ->and($display)->not->toContain('could not reach')
        ->and($display)->not->toContain('VPN');
})->with(['show', 'doctor', 'export']);

it('says the database to compare against does not exist', function (): void {
    $tester = trussCliTester('diff');
    $status = $tester->execute([
        '--dsn' => trussLaneDsnOrSkip((string) getenv('DB_PASSWORD')),
        '--against' => trussLaneDsnOrSkip((string) getenv('DB_PASSWORD'), 'no_such_database_7f3a'),
    ]);
    $display = $tester->getDisplay();

    expect($status)->toBe(2)
        ->and($display)->toContain('The database to compare against does not exist on its server')
        ->and($display)->not->toContain('VPN');
});

it('says the SQLite file does not exist instead of blaming the network', function (string $command): void {
    $path = sys_get_temp_dir().'/truss-missing-'.bin2hex(random_bytes(4)).'.sqlite';

    $tester = trussCliTester($command);
    $status = $tester->execute(['--dsn' => $path]);
    $display = $tester->getDisplay();

    expect($status)->toBe(2)
        ->and($display)->toContain('There is no SQLite file for the database')
        ->and($display)->toContain($path)
        ->and($display)->toContain('Check the path')
        ->and($display)->not->toContain('could not reach')
        ->and($display)->not->toContain('VPN');
})->with(['show', 'doctor', 'export']);

it('says the SQLite file to compare against does not exist', function (): void {
    $tester = trussCliTester('diff');
    $status = $tester->execute([
        '--dsn' => 'sqlite:'.trussCliFixture(),
        '--against' => sys_get_temp_dir().'/truss-missing-'.bin2hex(random_bytes(4)).'.sqlite',
    ]);

    expect($status)->toBe(2)
        ->and($tester->getDisplay())->toContain('There is no SQLite file for the database to compare against')
        ->and($tester->getDisplay())->not->toContain('VPN');
});

it('never prints the password in a schema-load error', function (): void {
    // The schema-load path prints the exception's own message, and nothing
    // stops a driver or a wrapper from echoing the password back. So the
    // command a real server let in fails on purpose, with a message that
    // carries the password, and the password must not reach the output.
    $password = (string) getenv('DB_PASSWORD');
    $dsn = trussLaneDsnOrSkip($password);

    if ($password === '') {
        test()->markTestSkipped('The lane has no password to leak.');
    }

    $command = new class('leaky') extends ConnectionCommand
    {
        public static string $password = '';

        protected function handle(InputInterface $input, OutputInterface $output, Container $container): int
        {
            return $this->schemaLoadFailed($output, new RuntimeException('authentication with '.self::$password.' expired'));
        }
    };
    $command::$password = $password;

    $tester = new CommandTester($command);
    $status = $tester->execute(['--dsn' => $dsn]);

    expect($status)->toBe(2)
        ->and($tester->getDisplay())->toContain('Could not load the schema')
        ->and($tester->getDisplay())->not->toContain($password);
});
