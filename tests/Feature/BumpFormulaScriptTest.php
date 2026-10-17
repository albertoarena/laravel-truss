<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * The Homebrew formula bump, as a script a developer can run by hand.
 *
 * A tap that still names the previous version installs the old binary while
 * `brew upgrade` reports nothing to do, so it fails silently in both
 * directions. The release workflow therefore bumps the formula on every
 * published release, and the edit itself lives in build/bump-formula.sh for
 * the reason smoke.sh and floor.sh do: the workflow can only be exercised by
 * releasing, and the script can be exercised here.
 *
 * The fixture is the formula as merged in the tap (homebrew-truss#2), pinned to
 * v1.15.0. Rewriting it to its own values must change nothing: that is the dry
 * run the release rules require before the job is trusted with v1.15.1.
 */

const BUMP_V1150_SHA = '5dfbfd8d53690eb462dac8521e4fae9ddc43a30cbb7073a95405d02390f34fec';

function bumpFixture(): string
{
    return (string) file_get_contents(dirname(__DIR__).'/Fixtures/homebrew/truss.rb');
}

/**
 * Writes $formula to a scratch file, runs the script on it, and returns the
 * exit code, the file as it was left, and what the script printed.
 *
 * @return array{exit: int, formula: string, output: string}
 */
function runBump(string $formula, string $tag, string $sha): array
{
    $path = tempnam(sys_get_temp_dir(), 'truss-formula-');
    file_put_contents($path, $formula);

    $process = new Process(['bash', dirname(__DIR__, 2).'/build/bump-formula.sh', $path, $tag, $sha]);
    $process->run();

    $result = [
        'exit' => (int) $process->getExitCode(),
        'formula' => (string) file_get_contents($path),
        'output' => $process->getOutput().$process->getErrorOutput(),
    ];

    unlink($path);

    return $result;
}

it('rewrites the url version and the sha256 to the new release', function (): void {
    $sha = str_repeat('ab', 32);

    $result = runBump(bumpFixture(), 'v1.15.1', $sha);

    expect($result['exit'])->toBe(0, $result['output'])
        ->and($result['formula'])->toContain('  url "https://github.com/albertoarena/laravel-truss/releases/download/v1.15.1/truss.phar"')
        ->and($result['formula'])->toContain('  sha256 "'.$sha.'"')
        ->and($result['formula'])->not->toContain('v1.15.0')
        ->and($result['formula'])->not->toContain(BUMP_V1150_SHA);
});

it('leaves every other line of the formula exactly as it was', function (): void {
    // The formula carries a wrapper, a test block and comments a human wrote.
    // A bump that reflowed any of it would be a bump nobody can review.
    $before = explode("\n", bumpFixture());
    $after = explode("\n", runBump(bumpFixture(), 'v1.15.1', str_repeat('ab', 32))['formula']);

    expect($after)->toHaveCount(count($before));

    $changed = array_keys(array_diff_assoc($before, $after));

    expect($changed)->toHaveCount(2);

    foreach ($changed as $line) {
        expect($before[$line])->toMatch('/^  (url|sha256) "/');
    }
});

it('changes nothing when given the release the formula already pins', function (): void {
    // The dry run. It is what proves the anchors match the real formula
    // before the job is trusted with a release nobody has checked by hand.
    $result = runBump(bumpFixture(), 'v1.15.0', BUMP_V1150_SHA);

    expect($result['exit'])->toBe(0, $result['output'])
        ->and($result['formula'])->toBe(bumpFixture());
});

it('never adds a version line', function (): void {
    // Homebrew reads the version from the url. A version line beside it is a
    // second place to forget, and the tap's audit flags it as redundant.
    $result = runBump(bumpFixture(), 'v1.15.1', str_repeat('ab', 32));

    expect($result['exit'])->toBe(0, $result['output'])
        ->and($result['formula'])->not->toMatch('/^\s*version /m');
});

it('refuses, and leaves the file alone, when an anchor is missing or repeated', function (string $formula): void {
    // Zero matches means the formula changed shape and the bump would silently
    // do nothing. Two means it cannot know which one ships. Both are failures
    // somebody must see, never a guess.
    $result = runBump($formula, 'v1.15.1', str_repeat('ab', 32));

    expect($result['exit'])->toBe(1, $result['output'])
        ->and($result['output'])->toContain('FAIL')
        ->and($result['formula'])->toBe($formula);
})->with([
    'no url line' => fn (): string => preg_replace('/^  url .*\n/m', '', bumpFixture()),
    'two url lines' => fn (): string => preg_replace('/^(  url .*\n)/m', '$1$1', bumpFixture()),
    'no sha256 line' => fn (): string => preg_replace('/^  sha256 .*\n/m', '', bumpFixture()),
    'two sha256 lines' => fn (): string => preg_replace('/^(  sha256 .*\n)/m', '$1$1', bumpFixture()),
]);

it('refuses a tag that is not a plain release version', function (string $tag): void {
    $result = runBump(bumpFixture(), $tag, str_repeat('ab', 32));

    expect($result['exit'])->toBe(1, $result['output'])
        ->and($result['output'])->toContain('FAIL')
        ->and($result['formula'])->toBe(bumpFixture());
})->with(['1.15.1', 'v1.15', 'v1.15.1-rc1', '', 'v1.15.1/../x']);

it('refuses a digest that is not 64 lowercase hex characters', function (string $sha): void {
    $result = runBump(bumpFixture(), 'v1.15.1', $sha);

    expect($result['exit'])->toBe(1, $result['output'])
        ->and($result['output'])->toContain('FAIL')
        ->and($result['formula'])->toBe(bumpFixture());
})->with([str_repeat('a', 63), str_repeat('A', 64), str_repeat('g', 64), '']);
