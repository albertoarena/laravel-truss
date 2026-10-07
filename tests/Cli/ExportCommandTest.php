<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cli\Application;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Command\Command;

/*
 * The binary's export, which is the command with a contract rather than a
 * presentation: the bytes it produces are committed to repositories and
 * compared by `--check` in CI, so they belong to the format and not to the
 * surface that printed them. The parity test in tests/Feature/Cli asserts the
 * two surfaces agree byte for byte; this file covers the command's own
 * behaviour and its exit codes.
 *
 * The exit codes are deliberately the artisan command's: 0 written or up to
 * date, 1 drift found by --check, 2 a usage or runtime error. Symfony's
 * Command::INVALID is 2, so the two surfaces agree without either of them
 * hardcoding a number the other does not know about.
 */

afterEach(function (): void {
    Container::setInstance(null);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
    putenv('TRUSS_DSN');
});

it('writes an export to stdout so it pipes', function (): void {
    $tester = trussCliTester('export');

    $status = $tester->execute(['--dsn' => 'sqlite:'.trussCliFixture(), '--format' => 'json']);
    $payload = json_decode($tester->getDisplay(), true);

    expect($status)->toBe(Command::SUCCESS)
        ->and($payload)->toBeArray()
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);
});

it('falls back to the configured default format', function (): void {
    // dbml in the packaged config, and the point is that the binary reads the
    // same key rather than carrying a default of its own.
    $tester = trussCliTester('export');
    $tester->execute(['--dsn' => 'sqlite:'.trussCliFixture()]);

    expect($tester->getDisplay())->toContain('Table users');
});

it('exports structure and never a row', function (): void {
    $path = trussCliFixture();
    (new PDO('sqlite:'.$path))->exec("insert into users (email) values ('nobody@example.com')");

    $tester = trussCliTester('export');
    $tester->execute(['--dsn' => 'sqlite:'.$path, '--format' => 'json']);

    expect($tester->getDisplay())->toContain('users')
        ->and($tester->getDisplay())->not->toContain('nobody@example.com');
});

it('refuses an unknown format with the list of real ones, and exit 2', function (): void {
    $tester = trussCliTester('export');

    expect($tester->execute(['--dsn' => 'sqlite:'.trussCliFixture(), '--format' => 'yaml']))->toBe(2)
        ->and($tester->getDisplay())->toContain('dbml')
        ->and($tester->getDisplay())->toContain('json');
});

it('refuses --check without --output, because there is nothing to compare', function (): void {
    $tester = trussCliTester('export');

    expect($tester->execute(['--dsn' => 'sqlite:'.trussCliFixture(), '--check' => true]))->toBe(2)
        ->and($tester->getDisplay())->toContain('--output');
});

it('refuses html on stdout, because the file is megabytes of Mermaid', function (): void {
    // The one format whose stdout default is wrong: a forgotten redirect fills
    // a terminal or a CI log with 3.6 MB and no way back.
    $tester = trussCliTester('export');

    expect($tester->execute(['--dsn' => 'sqlite:'.trussCliFixture(), '--format' => 'html']))->toBe(2)
        ->and($tester->getDisplay())->toContain('--output');
});

it('writes a file when asked, and says where', function (): void {
    $out = tempnam(sys_get_temp_dir(), 'truss-export-');
    $tester = trussCliTester('export');

    $status = $tester->execute(['--dsn' => 'sqlite:'.trussCliFixture(), '--format' => 'json', '--output' => $out]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain($out)
        ->and(json_decode((string) file_get_contents($out), true))->toBeArray();
});

it('reports an up-to-date file as success', function (): void {
    $fixture = trussCliFixture();
    $out = tempnam(sys_get_temp_dir(), 'truss-export-');

    trussCliTester('export')->execute(['--dsn' => 'sqlite:'.$fixture, '--format' => 'json', '--output' => $out]);

    $tester = trussCliTester('export');
    $status = $tester->execute(['--dsn' => 'sqlite:'.$fixture, '--format' => 'json', '--output' => $out, '--check' => true]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('up to date');
});

it('reports drift as exit 1, which is what fails a CI gate', function (): void {
    // 1 and not 2: a stale committed export is a different outcome from a
    // broken invocation, and a CI job needs to tell them apart.
    $out = tempnam(sys_get_temp_dir(), 'truss-export-');
    file_put_contents($out, "something stale\n");

    $tester = trussCliTester('export');
    $status = $tester->execute([
        '--dsn' => 'sqlite:'.trussCliFixture(), '--format' => 'json', '--output' => $out, '--check' => true,
    ]);

    expect($status)->toBe(1)
        ->and($tester->getDisplay())->toContain('out of date')
        ->and(file_get_contents($out))->toBe("something stale\n");
});

it('narrows to the tables asked for', function (): void {
    $tester = trussCliTester('export');
    $tester->execute(['--dsn' => 'sqlite:'.trussCliFixture(), '--format' => 'json', '--tables' => 'users']);

    expect($tester->getDisplay())->toContain('users')
        ->and($tester->getDisplay())->not->toContain('orders');
});

it('drops the tables excluded on the command line', function (): void {
    $tester = trussCliTester('export');
    $tester->execute(['--dsn' => 'sqlite:'.trussCliFixture(), '--format' => 'json', '--exclude' => 'orders']);

    expect($tester->getDisplay())->toContain('users')
        ->and($tester->getDisplay())->not->toContain('orders');
});

it('exits 2 when the filters leave nothing, rather than writing an empty export', function (): void {
    $tester = trussCliTester('export');

    expect($tester->execute([
        '--dsn' => 'sqlite:'.trussCliFixture(), '--format' => 'json', '--tables' => 'nothing_by_that_name',
    ]))->toBe(2);
});

it('has no --connection option, because one invocation reads one database', function (): void {
    // The artisan command chooses among the connections an application
    // configures. A DSN is the choice here, so a second way to make it would
    // be a way to disagree with it.
    $definition = Application::create()->find('export')->getDefinition();

    expect($definition->hasOption('connection'))->toBeFalse()
        ->and($definition->hasOption('dsn'))->toBeTrue();
});
