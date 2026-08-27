<?php

declare(strict_types=1);

use Laravel\Boost\Install\ThirdPartyPackage;
use Laravel\Boost\Support\Composer as BoostComposer;

/**
 * The one test that catches Boost changing its discovery convention.
 *
 * Everything else in tests/Feature/Boost asserts our own files against our own
 * expectations, so all of it would stay green while the integration was
 * silently dead in the field. Boost's discovery is convention in code, not a
 * published contract, and it can move without a deprecation.
 *
 * Boost is deliberately not a dependency of this package: Truss must install,
 * boot and test identically in an app that has never heard of it. So this test
 * skips itself when Boost is absent, which is the normal case locally and in
 * every CI lane but one. The dedicated lane installs Boost and runs this group.
 *
 * Discovery needs two things together, which is why the fixture exists: the
 * application's own composer.json must name the package (Boost reads require
 * and require-dev, not the installed set), and base_path('vendor/<name>') must
 * be a real directory. A testbench skeleton provides neither.
 */
beforeEach(function () {
    if (! class_exists(BoostComposer::class)) {
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

    $this->app->setBasePath($this->fixtureBase);
});

afterEach(function () {
    if (! isset($this->fixtureBase) || ! is_dir($this->fixtureBase)) {
        return;
    }

    @unlink($this->fixtureBase.'/vendor/albertoarena/laravel-truss');
    @unlink($this->fixtureBase.'/composer.json');
    @rmdir($this->fixtureBase.'/vendor/albertoarena');
    @rmdir($this->fixtureBase.'/vendor');
    @rmdir($this->fixtureBase);
});

it('is discovered by Boost as a package shipping guidelines', function () {
    expect(array_keys(BoostComposer::packagesDirectoriesWithBoostGuidelines()))
        ->toContain('albertoarena/laravel-truss');
});

it('is discovered by Boost as a package shipping skills', function () {
    expect(array_keys(BoostComposer::packagesDirectoriesWithBoostSkills()))
        ->toContain('albertoarena/laravel-truss');
});

it('offers both features at the moment a user chooses', function () {
    // This label is the entire pitch, at the one point where a user decides
    // whether to install our content. We do not control its wording, only
    // whether it reads "(guidelines, skills)" or the poorer "(guideline)".
    $package = ThirdPartyPackage::discover()->get('albertoarena/laravel-truss');

    expect($package)->not->toBeNull()
        ->and($package->displayLabel())->toBe('albertoarena/laravel-truss (guidelines, skills)');
});
