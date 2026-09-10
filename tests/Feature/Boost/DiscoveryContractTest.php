<?php

declare(strict_types=1);

use Laravel\Boost\Install\ThirdPartyPackage;
use Laravel\Roster\ProjectManager;

/**
 * The one test that catches Boost changing its discovery convention.
 *
 * Everything else in tests/Feature/Boost asserts our own files against our own
 * expectations, so all of it would stay green while the integration was
 * silently dead in the field. Boost's discovery is convention in code, not a
 * published contract, and it can move without a deprecation.
 *
 * It moved in laravel/boost v2.8.1, released 10/09/2026, and this test caught it
 * within hours, which is the whole reason the lane floats on the latest Boost
 * instead of pinning. What changed:
 *
 *   - `Support\Composer::packagesDirectoriesWithBoost{Guidelines,Skills}()` is
 *     gone. `ThirdPartyPackage::{guideline,skill}Directories()` replaces it.
 *   - Discovery now runs through Laravel Roster: every entry point takes a
 *     `ProjectManager`, which scans the project rather than reading composer.json
 *     directly, so the fixture needs a composer.lock as well.
 *   - Only *direct*, non-first-party dependencies are considered, so a package
 *     pulled in transitively can no longer inject guidelines. Truss is a direct
 *     dependency of any app that installs it, so it still qualifies.
 *
 * What did not change is the layout Truss ships: `resources/boost/guidelines`
 * and `resources/boost/skills` inside the package, which is still exactly what
 * `PackageRegistry::boostPath()` looks for.
 *
 * Boost is deliberately not a dependency of this package: Truss must install,
 * boot and test identically in an app that has never heard of it. So this test
 * skips itself when Boost is absent, which is the normal case locally and in
 * every CI lane but one. The dedicated lane installs Boost and runs this group.
 *
 * Discovery needs three things together, which is why the fixture exists: the
 * application's own composer.json must name the package (that is what marks it
 * a direct dependency), its composer.lock must list it (that is where Roster
 * reads the installed set), and base_path('vendor/<name>') must be a real
 * directory holding the package. A testbench skeleton provides none of them.
 */
beforeEach(function () {
    if (! class_exists(ThirdPartyPackage::class)) {
        $this->markTestSkipped('laravel/boost is not installed; this group runs in its own CI lane.');
    }

    $packageRoot = dirname(__DIR__, 3);

    $this->fixtureBase = sys_get_temp_dir().'/truss-boost-contract-'.getmypid();
    $vendorDir = $this->fixtureBase.'/vendor/albertoarena';

    if (! is_dir($vendorDir)) {
        mkdir($vendorDir, 0777, true);
    }

    if (! file_exists($vendorDir.'/laravel-truss')) {
        symlink($packageRoot, $vendorDir.'/laravel-truss');
    }

    file_put_contents($this->fixtureBase.'/composer.json', json_encode([
        'require' => ['albertoarena/laravel-truss' => '*'],
    ]));

    // Roster reads the lock file for what is installed and the manifest for what
    // is direct, and a package missing from either is not discovered at all.
    file_put_contents($this->fixtureBase.'/composer.lock', json_encode([
        'packages' => [['name' => 'albertoarena/laravel-truss', 'version' => 'v1.11.1']],
        'packages-dev' => [],
    ]));

    $this->app->setBasePath($this->fixtureBase);
});

afterEach(function () {
    if (! isset($this->fixtureBase) || ! is_dir($this->fixtureBase)) {
        return;
    }

    @unlink($this->fixtureBase.'/vendor/albertoarena/laravel-truss');
    @unlink($this->fixtureBase.'/composer.json');
    @unlink($this->fixtureBase.'/composer.lock');
    @rmdir($this->fixtureBase.'/vendor/albertoarena');
    @rmdir($this->fixtureBase.'/vendor');
    @rmdir($this->fixtureBase);
});

/** The scanner Boost's discovery now runs on, pointed at the fixture app. */
function boostProject(): ProjectManager
{
    return app(ProjectManager::class);
}

it('is discovered by Boost as a package shipping guidelines', function () {
    expect(array_keys(ThirdPartyPackage::guidelineDirectories(boostProject())))
        ->toContain('albertoarena/laravel-truss');
});

it('is discovered by Boost as a package shipping skills', function () {
    expect(array_keys(ThirdPartyPackage::skillDirectories(boostProject())))
        ->toContain('albertoarena/laravel-truss');
});

it('resolves both feature directories inside the installed package', function () {
    // The convention that has to keep holding on our side. Boost looks for
    // resources/boost/<feature> under the package's own path, so this fails if
    // the layout is ever moved or renamed here, as opposed to upstream.
    $guidelines = ThirdPartyPackage::guidelineDirectories(boostProject())['albertoarena/laravel-truss'];
    $skills = ThirdPartyPackage::skillDirectories(boostProject())['albertoarena/laravel-truss'];

    expect(is_dir($guidelines))->toBeTrue()
        ->and(is_dir($skills))->toBeTrue()
        ->and($guidelines)->toEndWith('resources/boost/guidelines')
        ->and($skills)->toEndWith('resources/boost/skills');
});

it('offers both features at the moment a user chooses', function () {
    // This label is the entire pitch, at the one point where a user decides
    // whether to install our content. We do not control its wording, only
    // whether it reads "(guidelines, skills)" or the poorer "(guideline)".
    $package = ThirdPartyPackage::discover(boostProject())->get('albertoarena/laravel-truss');

    expect($package)->not->toBeNull()
        ->and($package->hasGuidelines)->toBeTrue()
        ->and($package->hasSkills)->toBeTrue()
        ->and($package->displayLabel())->toBe('albertoarena/laravel-truss (guidelines, skills)');
});
