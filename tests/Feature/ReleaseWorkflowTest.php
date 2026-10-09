<?php

declare(strict_types=1);

/*
 * The release workflow, guarded as text.
 *
 * Nothing here runs GitHub Actions, and no test can: the workflow is the one
 * part of the build that is only ever exercised by cutting a tag. That is
 * exactly why the work it does lives in build/smoke.sh and build/floor.sh,
 * which a developer can run against a local compile, and why the workflow is a
 * thin wrapper around them.
 *
 * What is left to assert is the wrapper's shape, and only the parts that are
 * load-bearing and silent when wrong. ext-yaml is not installed, so these read
 * the file as text; a structural parser would be nicer and would not catch
 * anything more.
 */

function releaseWorkflow(): string
{
    $path = dirname(__DIR__, 2).'/.github/workflows/release.yml';

    expect($path)->toBeFile();

    return (string) file_get_contents($path);
}

it('runs on a tag and not on a branch', function (): void {
    // The binary and the package ship from one tag, so they cannot disagree
    // about one database. A build from a branch is how that promise breaks.
    expect(releaseWorkflow())->toContain("tags:\n      - 'v*'");
});

it('attaches only on a published release, not on the tag push', function (): void {
    // The documented release order pushes the tag before creating the release
    // by hand, so a build on the tag push has nothing to upload to. Gating on
    // the event rather than the ref type is what makes the two orders agree:
    // a tag push builds and smokes, a published release also attaches.
    expect(releaseWorkflow())->toContain("if: github.event_name == 'release'")
        ->and(releaseWorkflow())->toContain('types: [published]');
});

it('pins every action to a full commit, as the rest of this repository does', function (): void {
    // Supply-chain hardening from v1.7.0: a moving tag is somebody else's
    // write access to this release.
    preg_match_all('/uses:\s*(\S+)/', releaseWorkflow(), $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $ref) {
        expect($ref)->toMatch('/@[0-9a-f]{40}$/', "[{$ref}] is not pinned to a full commit SHA.");
    }
});

it('installs the bundled runtime from the lock rather than updating it', function (): void {
    // build/composer.lock is committed so that two builds of one tag bundle
    // the same Illuminate patch versions. `composer update` here would make
    // the lock decorative and the version number a lie about the contents.
    expect(releaseWorkflow())->toContain('composer install --working-dir=build --no-dev')
        ->and(releaseWorkflow())->not->toContain('composer update --working-dir=build');
});

it('fetches a pinned Box rather than the latest release', function (): void {
    // "latest" would mean the binary's build system changes under it without
    // a commit here, which is the same class of problem as an unpinned action.
    expect(releaseWorkflow())->toMatch('#releases/download/\d+\.\d+\.\d+/box\.phar#')
        ->and(releaseWorkflow())->not->toContain('releases/latest/download/box.phar');
});

it('checks out the full history, because the version comes from git describe', function (): void {
    // A shallow clone stamps a bare commit hash, and the smoke lane then fails
    // its version check: the right failure for the wrong reason.
    expect(releaseWorkflow())->toContain('fetch-depth: 0');
});

it('smokes the artifact on the floor and on the newest PHP Homebrew ships', function (string $version): void {
    // 8.5 is the one that matters most and is easiest to leave out: the
    // formula installs no interpreter, so a tap user runs the binary on their
    // own PHP, and this is the only lane that tests what that executes.
    expect(releaseWorkflow())->toContain("'".$version."'");
})->with(['8.2', '8.5']);

it('asserts the floor guard on a PHP below the floor', function (): void {
    expect(releaseWorkflow())->toContain("php-version: '8.1'")
        ->and(releaseWorkflow())->toContain('build/floor.sh');
});

it('runs the two scripts rather than inlining their work', function (string $script): void {
    // Both are executable by hand against a local compile. A workflow that
    // inlined these assertions could only ever be tested by releasing.
    expect(releaseWorkflow())->toContain($script)
        ->and(dirname(__DIR__, 2).'/'.$script)->toBeFile();
})->with(['build/smoke.sh', 'build/floor.sh']);

it('does not attach anything the smoke lanes have not passed', function (): void {
    // needs: both, or a broken binary reaches a release page.
    expect(releaseWorkflow())->toContain('needs: [smoke, floor]');
});

it('says where the formula bump goes and why it is not here yet', function (): void {
    // The one deliberate hole in this workflow. A bump written before there is
    // a release to bump is a bump written untested, which is how a tap
    // silently stops upgrading; and an undocumented hole is how it is
    // forgotten instead.
    expect(releaseWorkflow())->toContain('HOMEBREW_TAP_TOKEN')
        ->and(releaseWorkflow())->toContain('Formula/truss.rb');
});
