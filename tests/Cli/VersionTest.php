<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cli\Version;

/*
 * The binary has to be able to answer `--version` with the tag it was built
 * from, and there is no version constant anywhere else in this package: the git
 * tag is the only source of truth, and `composer.json` carries no version by
 * design. So the build stamps it, and the unreplaced placeholder has to behave
 * sensibly in a clone, where no build has happened.
 */

it('reads as a development build while the placeholder is unreplaced', function (): void {
    // This is the state in every clone and in the whole test suite. It must not
    // read as a version, or a bug report will quote a tag that never shipped.
    expect(Version::resolve(Version::PLACEHOLDER))->toBe('dev');
});

it('reads a stamped tag back exactly', function (): void {
    expect(Version::resolve('v1.15.0'))->toBe('v1.15.0');
});

it('tolerates a stamp that lost its leading v', function (): void {
    expect(Version::resolve('1.15.0'))->toBe('1.15.0');
});

it('falls back to dev on an empty stamp, rather than reporting nothing', function (string $stamp): void {
    // A build that substitutes an empty string is a broken build, and
    // `truss --version` printing "Truss" with a blank after it looks like a
    // Truss bug rather than a packaging one.
    expect(Version::resolve($stamp))->toBe('dev');
})->with(['', '   ']);

it('answers current() with a non-empty string in any state', function (): void {
    expect(Version::current())->toBeString()->not->toBeEmpty();
});
