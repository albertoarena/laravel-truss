<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cli\Application;
use AlbertoArena\Truss\Cli\Version;

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
})->with(['show']);

it('ships no open and no rebuild, and that is a decision rather than an omission', function (string $name): void {
    // `open` needs a route and an application URL to open, and framework-free
    // there is neither: no route, no server, nothing to point a browser at.
    // `rebuild` writes the snapshot into a cache store, and the CLI's store is
    // an array that dies with the process, so it would report success for
    // having done nothing. Both are Q12 in the plan, and both are tested
    // rather than merely left out, so that adding one is a deliberate act.
    expect(Application::create()->has($name))->toBeFalse("The binary must not ship [{$name}].");
})->with(['open', 'rebuild']);
