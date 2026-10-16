<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cli\Application;
use AlbertoArena\Truss\Cli\Version;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

it('is named for the tool rather than for the file', function (): void {
    expect(Application::create()->getName())->toBe('Truss');
});

it('reports the version the build stamped', function (): void {
    expect(Application::create()->getVersion())->toBe(Version::current());
});

it('ships the commands the binary can actually honour', function (string $name): void {
    // The set grows to show, export, doctor and diff. Each name joins this
    // dataset in the change that implements it, so the list is always what the
    // binary really answers rather than what it is expected to answer one day.
    expect(Application::create()->has($name))->toBeTrue("Expected the binary to ship [{$name}].");
})->with(['show', 'export', 'doctor', 'diff']);

it('ships no open and no rebuild, and that is a decision rather than an omission', function (string $name): void {
    // `open` needs a route and an application URL to open, and framework-free
    // there is neither: no route, no server, nothing to point a browser at.
    // `rebuild` writes the snapshot into a cache store, and the CLI's store is
    // an array that dies with the process, so it would report success for
    // having done nothing. Both are Q12 in the plan, and both are tested
    // rather than merely left out, so that adding one is a deliberate act.
    expect(Application::create()->has($name))->toBeFalse("The binary must not ship [{$name}].");
})->with(['open', 'rebuild']);

it('exits 2 when it cannot understand the command line, never 1', function (array $argv): void {
    // 1 is a verdict about the schema: doctor findings at the fail level, or
    // export drift. Symfony's default for a usage error is also 1, so a typo in
    // a CI script would read as "the structure has errors". Found 16/10/2026
    // while writing the Homebrew formula's test.
    $application = Application::create();
    $application->setAutoExit(false);

    $output = new BufferedOutput;

    expect($application->run(new ArrayInput($argv), $output))->toBe(2)
        ->and($output->fetch())->not->toBeEmpty();
})->with([
    'an unknown command' => [['command' => 'nosuchcmd']],
    'an unexpected argument' => [['command' => 'doctor', 'bogus' => 'bogus']],
    'an unknown option' => [['command' => 'show', '--nope' => true]],
]);
