<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cli\Application;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Command\Command;

/*
 * The one command that does something the package cannot. Inside an
 * application, `truss:diff` compares the current schema against the baseline
 * recorded before the last migration, which is a question about time. Given
 * two connection strings it becomes a question about place: what differs
 * between staging and production, right now.
 *
 * No baseline is involved and none could be. A baseline is written by the
 * migration listener, there are no migrations here, and both sides of this
 * comparison are live, which is why the CLI needs no filesystem at all.
 */

function diffFixture(array $tables): string
{
    $base = (string) tempnam(sys_get_temp_dir(), 'truss-diff-');
    $path = $base.'.sqlite';
    @unlink($base);

    $pdo = new PDO('sqlite:'.$path);

    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }

    return $path;
}

afterEach(function (): void {
    Container::setInstance(null);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
    putenv('TRUSS_DSN');
});

it('reports two identical databases as having no differences', function (): void {
    $schema = ['create table users (id integer primary key, email text)'];

    $tester = trussCliTester('diff');
    $status = $tester->execute([
        '--dsn' => 'sqlite:'.diffFixture($schema),
        '--against' => 'sqlite:'.diffFixture($schema),
    ]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('No structural differences');
});

it('names a table the first database has and the second does not', function (): void {
    // Direction is the classic bug in a two-sided diff, so it is pinned: --dsn
    // is the current state and --against is what it is compared to, which
    // makes this an addition rather than a removal.
    $tester = trussCliTester('diff');
    $status = $tester->execute([
        '--dsn' => 'sqlite:'.diffFixture([
            'create table users (id integer primary key)',
            'create table invoices (id integer primary key)',
        ]),
        '--against' => 'sqlite:'.diffFixture(['create table users (id integer primary key)']),
    ]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('Added tables')
        ->and($tester->getDisplay())->toContain('+ invoices');
});

it('names a table the second database has and the first does not as removed', function (): void {
    $tester = trussCliTester('diff');
    $tester->execute([
        '--dsn' => 'sqlite:'.diffFixture(['create table users (id integer primary key)']),
        '--against' => 'sqlite:'.diffFixture([
            'create table users (id integer primary key)',
            'create table legacy_import (id integer primary key)',
        ]),
    ]);

    expect($tester->getDisplay())->toContain('Removed tables')
        ->and($tester->getDisplay())->toContain('- legacy_import');
});

it('describes a column that only one side has', function (): void {
    $tester = trussCliTester('diff');
    $tester->execute([
        '--dsn' => 'sqlite:'.diffFixture(['create table users (id integer primary key, nickname text)']),
        '--against' => 'sqlite:'.diffFixture(['create table users (id integer primary key)']),
    ]);

    expect($tester->getDisplay())->toContain('~ users')
        ->and($tester->getDisplay())->toContain('column added: nickname');
});

it('renders through the same formatter as the artisan command', function (): void {
    // Shared DiffRenderer, so the body is identical and only the headline
    // differs. Asserting the markers rather than the whole block, because the
    // renderer has its own unit tests.
    $tester = trussCliTester('diff');
    $tester->execute([
        '--dsn' => 'sqlite:'.diffFixture(['create table a (id integer primary key)', 'create table b (id integer primary key)']),
        '--against' => 'sqlite:'.diffFixture(['create table a (id integer primary key)']),
    ]);

    expect($tester->getDisplay())->toMatch('/Added tables:\s*\n\s*\+ b/');
});

it('says what it needs when given only one database', function (): void {
    // There is no baseline to fall back on, so a one-sided diff is a usage
    // error rather than a different mode.
    $tester = trussCliTester('diff');

    expect($tester->execute(['--dsn' => 'sqlite:'.diffFixture(['create table a (id integer primary key)'])]))->toBe(2)
        ->and($tester->getDisplay())->toContain('--against');
});

it('reports an unusable second connection string without a stack trace', function (): void {
    $tester = trussCliTester('diff');

    expect($tester->execute([
        '--dsn' => 'sqlite:'.diffFixture(['create table a (id integer primary key)']),
        '--against' => 'shop',
    ]))->toBe(2)
        ->and($tester->getDisplay())->toContain('could not be understood');
});

it('reports an unreachable second database by driver and host, never by password', function (): void {
    $tester = trussCliTester('diff');

    $status = $tester->execute([
        '--dsn' => 'sqlite:'.diffFixture(['create table a (id integer primary key)']),
        '--against' => 'mysql://truss:hunter2@127.0.0.1:1/shop',
    ]);

    expect($status)->toBe(2)
        ->and($tester->getDisplay())->toContain('127.0.0.1')
        ->and($tester->getDisplay())->not->toContain('hunter2');
});

it('names both databases in its headline, with neither set of credentials', function (): void {
    $tester = trussCliTester('diff');
    $left = diffFixture(['create table a (id integer primary key)']);
    $right = diffFixture(['create table a (id integer primary key)']);

    $tester->execute(['--dsn' => 'sqlite:'.$left, '--against' => 'sqlite:'.$right]);

    expect($tester->getDisplay())->toContain($left)
        ->and($tester->getDisplay())->toContain($right);
});

it('never prints a row of data from either side', function (): void {
    $left = diffFixture(['create table users (id integer primary key, email text)']);
    (new PDO('sqlite:'.$left))->exec("insert into users (email) values ('nobody@example.com')");

    $tester = trussCliTester('diff');
    $tester->execute(['--dsn' => 'sqlite:'.$left, '--against' => 'sqlite:'.diffFixture(['create table users (id integer primary key)'])]);

    expect($tester->getDisplay())->not->toContain('nobody@example.com');
});

it('has no --connection option, because both sides are connection strings', function (): void {
    $definition = Application::create()->find('diff')->getDefinition();

    expect($definition->hasOption('connection'))->toBeFalse()
        ->and($definition->hasOption('against'))->toBeTrue();
});
