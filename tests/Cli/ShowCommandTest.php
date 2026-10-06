<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cli\Application;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/*
 * The first command through the whole path: parse a DSN, boot the container,
 * read a real schema, print it. A temporary SQLite file rather than :memory:
 * on purpose, because the command opens its own connection from the string it
 * is given and an in-memory database would be a different, empty one.
 */

function trussShow(): CommandTester
{
    return new CommandTester(Application::create()->find('show'));
}

function sqliteFixture(): string
{
    $path = tempnam(sys_get_temp_dir(), 'truss-cli-').'.sqlite';

    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('create table users (id integer primary key, email text not null)');
    $pdo->exec('create table orders (id integer primary key, user_id integer references users(id), total integer)');
    // Shipped in the packaged excluded_tables list, so its absence from the
    // output is what proves the shared filter is wired in.
    $pdo->exec('create table migrations (id integer primary key, migration text)');

    return $path;
}

afterEach(function (): void {
    Container::setInstance(null);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
    putenv('TRUSS_DSN');
});

it('prints the structure of a database it is pointed at', function (): void {
    $tester = trussShow();

    $status = $tester->execute(['--dsn' => 'sqlite:'.sqliteFixture()]);
    $output = $tester->getDisplay();

    expect($status)->toBe(Command::SUCCESS)
        ->and($output)->toContain('users')
        ->and($output)->toContain('orders');
});

it('counts columns and foreign keys, which is the whole content of the table', function (): void {
    $tester = trussShow();
    $tester->execute(['--dsn' => 'sqlite:'.sqliteFixture()]);

    // orders has three columns and one foreign key, on one row together.
    expect($tester->getDisplay())->toMatch('/orders\s+\|\s+3\s+\|\s+1/');
});

it('never prints a row of data, only structure', function (): void {
    $path = sqliteFixture();
    (new PDO('sqlite:'.$path))->exec("insert into users (email) values ('nobody@example.com')");

    $tester = trussShow();
    $tester->execute(['--dsn' => 'sqlite:'.$path]);

    expect($tester->getDisplay())->not->toContain('nobody@example.com');
});

it('applies the configured exclusions, through the same filter as the artisan command', function (): void {
    $tester = trussShow();
    $tester->execute(['--dsn' => 'sqlite:'.sqliteFixture()]);

    expect($tester->getDisplay())->not->toContain('migrations');
});

it('takes the connection string from the environment when no option is given', function (): void {
    // The documented default, because a DSN in argv lands in shell history and
    // is visible in ps.
    putenv('TRUSS_DSN=sqlite:'.sqliteFixture());

    $tester = trussShow();

    expect($tester->execute([]))->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('users');
});

it('prefers the explicit option over the environment', function (): void {
    putenv('TRUSS_DSN=sqlite:/nowhere/that/exists.sqlite');

    $tester = trussShow();
    $status = $tester->execute(['--dsn' => 'sqlite:'.sqliteFixture()]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('users');
});

it('says how to supply a connection when given none', function (): void {
    $tester = trussShow();

    expect($tester->execute([]))->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('TRUSS_DSN');
});

it('reports an unusable connection string as a sentence, not a stack trace', function (): void {
    $tester = trussShow();

    expect($tester->execute(['--dsn' => 'shop']))->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('could not be understood')
        ->and($tester->getDisplay())->not->toContain('#0 ');
});

it('exits cleanly on an unreachable database instead of replaying migrations', function (): void {
    // The most likely first mistake: a wrong host, a closed port, no VPN. The
    // package's fallback replays the application's migrations on SQLite, and
    // there is no application here, so entering it resolves [migrator] out of
    // a container that has none. The CLI has to stop before that, and say
    // which database it could not reach.
    $tester = trussShow();
    $status = $tester->execute(['--dsn' => 'mysql://truss:hunter2@127.0.0.1:1/shop']);

    expect($status)->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('127.0.0.1')
        ->and($tester->getDisplay())->toContain('mysql')
        ->and($tester->getDisplay())->not->toContain('migrator');
});

it('never prints the password of a connection it could not reach', function (): void {
    $tester = trussShow();
    $tester->execute(['--dsn' => 'mysql://truss:hunter2@127.0.0.1:1/shop']);

    expect($tester->getDisplay())->not->toContain('hunter2');
});
