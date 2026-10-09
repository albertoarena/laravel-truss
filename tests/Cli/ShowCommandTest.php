<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Command\Command;

/*
 * The first command through the whole path: parse a DSN, boot the container,
 * read a real schema, print it. A temporary SQLite file rather than :memory:
 * on purpose, because the command opens its own connection from the string it
 * is given and an in-memory database would be a different, empty one.
 */

afterEach(function (): void {
    Container::setInstance(null);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
    putenv('TRUSS_DSN');
});

it('prints the structure of a database it is pointed at', function (): void {
    $tester = trussCliTester('show');

    $status = $tester->execute(['--dsn' => 'sqlite:'.trussCliFixture()]);
    $output = $tester->getDisplay();

    expect($status)->toBe(Command::SUCCESS)
        ->and($output)->toContain('users')
        ->and($output)->toContain('orders');
});

it('counts columns and foreign keys, which is the whole content of the table', function (): void {
    $tester = trussCliTester('show');
    $tester->execute(['--dsn' => 'sqlite:'.trussCliFixture()]);

    // orders has three columns and one foreign key, on one row together.
    expect($tester->getDisplay())->toMatch('/orders\s+\|\s+3\s+\|\s+1/');
});

it('never prints a row of data, only structure', function (): void {
    $path = trussCliFixture();
    (new PDO('sqlite:'.$path))->exec("insert into users (email) values ('nobody@example.com')");

    $tester = trussCliTester('show');
    $tester->execute(['--dsn' => 'sqlite:'.$path]);

    expect($tester->getDisplay())->not->toContain('nobody@example.com');
});

it('applies the configured exclusions, through the same filter as the artisan command', function (): void {
    $tester = trussCliTester('show');
    $tester->execute(['--dsn' => 'sqlite:'.trussCliFixture()]);

    expect($tester->getDisplay())->not->toContain('migrations');
});

it('takes the connection string from the environment when no option is given', function (): void {
    // The documented default, because a DSN in argv lands in shell history and
    // is visible in ps.
    putenv('TRUSS_DSN=sqlite:'.trussCliFixture());

    $tester = trussCliTester('show');

    expect($tester->execute([]))->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('users');
});

it('prefers the explicit option over the environment', function (): void {
    putenv('TRUSS_DSN=sqlite:/nowhere/that/exists.sqlite');

    $tester = trussCliTester('show');
    $status = $tester->execute(['--dsn' => 'sqlite:'.trussCliFixture()]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('users');
});

it('says how to supply a connection when given none', function (): void {
    // 2 rather than 1, and every command in the binary agrees on it: 1 is
    // reserved for a verdict about the schema (doctor findings, export drift),
    // so a usage error must not be able to impersonate one.
    $tester = trussCliTester('show');

    expect($tester->execute([]))->toBe(2)
        ->and($tester->getDisplay())->toContain('TRUSS_DSN');
});

it('reports an unusable connection string as a sentence, not a stack trace', function (): void {
    $tester = trussCliTester('show');

    expect($tester->execute(['--dsn' => 'shop']))->toBe(2)
        ->and($tester->getDisplay())->toContain('could not be understood')
        ->and($tester->getDisplay())->not->toContain('#0 ');
});

it('exits cleanly on an unreachable database instead of replaying migrations', function (): void {
    // The most likely first mistake: a wrong host, a closed port, no VPN. The
    // package's fallback replays the application's migrations on SQLite, and
    // there is no application here, so entering it resolves [migrator] out of
    // a container that has none. The CLI has to stop before that, and say
    // which database it could not reach.
    $tester = trussCliTester('show');
    $status = $tester->execute(['--dsn' => 'mysql://truss:hunter2@127.0.0.1:1/shop']);

    expect($status)->toBe(2)
        ->and($tester->getDisplay())->toContain('127.0.0.1')
        ->and($tester->getDisplay())->toContain('mysql')
        ->and($tester->getDisplay())->not->toContain('migrator');
});

it('never prints the password of a connection it could not reach', function (): void {
    $tester = trussCliTester('show');
    $tester->execute(['--dsn' => 'mysql://truss:hunter2@127.0.0.1:1/shop']);

    expect($tester->getDisplay())->not->toContain('hunter2');
});
