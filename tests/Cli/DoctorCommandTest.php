<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cli\Application;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Command\Command;

/*
 * The doctor from the binary. Its exit codes are the artisan command's,
 * because the thing consuming them is a CI job that cannot tell which surface
 * ran: 0 clean, 1 findings at or above the fail level, 2 a bad option or a
 * schema that could not be read.
 *
 * The fixture's orders.user_id is a foreign key with no index, which SQLite
 * does not create for you, so TRUSS-IDX-001 fires at error severity. That is
 * what makes the default exit code 1 here rather than 0.
 */

afterEach(function (): void {
    Container::setInstance(null);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
    putenv('TRUSS_DSN');
});

it('reports findings and exits non-zero at the default fail level', function (): void {
    $tester = trussCliTester('doctor');

    $status = $tester->execute(['--dsn' => 'sqlite:'.trussCliFixture()]);

    expect($status)->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('Truss doctor:')
        ->and($tester->getDisplay())->toContain('TRUSS-IDX-001');
});

it('exits zero on a schema with nothing to report', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'truss-clean-').'.sqlite';
    (new PDO('sqlite:'.$path))->exec('create table widgets (id integer primary key, label text)');

    $tester = trussCliTester('doctor');

    // "no findings." rather than "0 findings": the formatter has its own
    // wording for a clean pass, and it reads like a verdict rather than a count.
    expect($tester->execute(['--dsn' => 'sqlite:'.$path]))->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('no findings');
});

it('honours --fail-on=never, which is how a report runs without gating a build', function (): void {
    $tester = trussCliTester('doctor');

    $status = $tester->execute(['--dsn' => 'sqlite:'.trussCliFixture(), '--fail-on' => 'never']);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('TRUSS-IDX-001');
});

it('raises the bar with --fail-on, so a warning-level gate ignores nothing above it', function (): void {
    // The fixture's finding is an error, so a warning threshold still fails:
    // the flag is a floor, not an equality test.
    $tester = trussCliTester('doctor');

    expect($tester->execute(['--dsn' => 'sqlite:'.trussCliFixture(), '--fail-on' => 'warning']))->toBe(Command::FAILURE);
});

it('emits machine-readable findings with --format=json', function (): void {
    $tester = trussCliTester('doctor');
    $tester->execute(['--dsn' => 'sqlite:'.trussCliFixture(), '--format' => 'json', '--fail-on' => 'never']);

    $report = json_decode($tester->getDisplay(), true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($report['summary']['total'])->toBeGreaterThan(0)
        ->and($report['findings'][0]['code'])->toBe('TRUSS-IDX-001')
        ->and($report['findings'][0]['fingerprint'])->toBeString();
});

it('refuses an invalid option with exit 2 rather than guessing', function (array $options): void {
    $tester = trussCliTester('doctor');

    expect($tester->execute(['--dsn' => 'sqlite:'.trussCliFixture(), ...$options]))->toBe(2);
})->with([
    'format' => [['--format' => 'yaml']],
    'preset' => [['--preset' => 'aggressive']],
    'fail-on' => [['--fail-on' => 'sometimes']],
]);

it('narrows to one table', function (): void {
    $tester = trussCliTester('doctor');
    $tester->execute(['--dsn' => 'sqlite:'.trussCliFixture(), '--table' => 'users', '--fail-on' => 'never']);

    expect($tester->getDisplay())->not->toContain('TRUSS-IDX-001');
});

it('skips a category, so a run can ignore a whole class of finding', function (): void {
    $tester = trussCliTester('doctor');
    $tester->execute(['--dsn' => 'sqlite:'.trussCliFixture(), '--skip' => 'index', '--fail-on' => 'never']);

    expect($tester->getDisplay())->not->toContain('TRUSS-IDX-001');
});

it('answers to check as well, like the artisan command does', function (): void {
    // truss:doctor aliases truss:check. The binary keeps the alias so muscle
    // memory carries across, and so documentation can use either.
    expect(Application::create()->has('check'))->toBeTrue();
});

it('never prints a row of data', function (): void {
    $path = trussCliFixture();
    (new PDO('sqlite:'.$path))->exec("insert into users (email) values ('nobody@example.com')");

    $tester = trussCliTester('doctor');
    $tester->execute(['--dsn' => 'sqlite:'.$path, '--fail-on' => 'never']);

    expect($tester->getDisplay())->not->toContain('nobody@example.com');
});

it('has no --connection option, because one invocation reads one database', function (): void {
    $definition = Application::create()->find('doctor')->getDefinition();

    expect($definition->hasOption('connection'))->toBeFalse()
        ->and($definition->hasOption('dsn'))->toBeTrue();
});

it('reports an unreachable database as 2, never as 1, because 1 already means findings', function (): void {
    // The one exit code in this command that carries two meanings if nobody is
    // careful. A CI job reading 1 concludes "this schema has errors at or
    // above the fail level" and prints the doctor's report; if an unreachable
    // host could also produce 1, that job reports a schema problem that does
    // not exist and hides an infrastructure one that does. The artisan command
    // already draws this line: 2 is a configuration, connection or snapshot
    // error.
    $tester = trussCliTester('doctor');

    expect($tester->execute(['--dsn' => 'mysql://truss:hunter2@127.0.0.1:1/shop']))->toBe(2)
        ->and($tester->getDisplay())->not->toContain('TRUSS-');
});

it('reports a missing or unusable connection string as 2 as well', function (array $args): void {
    // Same reasoning: these are usage errors, and they must not be confused
    // with a verdict about a schema.
    expect(trussCliTester('doctor')->execute($args))->toBe(2);
})->with([
    'none given' => [[]],
    'not a connection string' => [[['--dsn' => 'shop']][0]],
]);
