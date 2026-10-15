<?php

declare(strict_types=1);

/*
 * The guard the binary runs before anything else.
 *
 * It exists because the Homebrew formula installs no PHP: the binary runs on
 * whatever interpreter the user already has, so the failure moved from install
 * time to first run. Below the floor, Illuminate fatals somewhere deep with a
 * syntax or signature error that names none of this; the guard turns that into
 * one sentence naming both versions.
 *
 * Two constraints shape the file under test rather than this one. It is loaded
 * **before** the Composer autoloader, because the autoloader would parse class
 * files written for a PHP the running interpreter may not understand, so the
 * guard cannot be a class. And it is written in PHP 7 syntax, since a machine
 * with no PHP at all is a brew install away from an old one, and a parse error
 * in the guard is the one thing worse than the error it prevents.
 */

beforeEach(function (): void {
    require_once dirname(__DIR__, 2).'/bin/php-floor.php';
});

it('passes a version at or above the floor', function (string $found): void {
    expect(truss_php_floor_failure($found, '8.2'))->toBeNull();
})->with(['8.2.0', '8.2.29', '8.3.14', '8.4.2', '8.5.0', '8.5.0RC1', '8.2.0-dev']);

it('fails a version below the floor', function (string $found): void {
    expect(truss_php_floor_failure($found, '8.2'))->toBeString();
})->with(['8.1.29', '8.0.30', '7.4.33']);

it('names both versions, because neither alone tells the reader what to do', function (): void {
    $message = truss_php_floor_failure('8.1.29', '8.2');

    expect($message)->toContain('8.2')
        ->and($message)->toContain('8.1.29')
        ->and($message)->toContain('Truss');
});

it('keeps the message to a single line', function (): void {
    // It is printed to stderr by a shell script's worth of code, with no
    // formatter and no wrapping, and it may be the only output a user sees.
    expect(truss_php_floor_failure('8.1.29', '8.2'))->not->toContain("\n");
});

it('can be loaded twice without redeclaring', function (): void {
    // bin/truss requires it, and so does this test. Unguarded, the second
    // require is a fatal that takes the run with it.
    require dirname(__DIR__, 2).'/bin/php-floor.php';

    expect(function_exists('truss_php_floor_failure'))->toBeTrue();
});
