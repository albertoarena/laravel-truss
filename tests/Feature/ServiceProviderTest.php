<?php

declare(strict_types=1);

use AlbertoArena\Truss\TrussServiceProvider;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Gate;

it('boots and registers the Truss service provider', function () {
    expect(app()->getProvider(TrussServiceProvider::class))
        ->toBeInstanceOf(TrussServiceProvider::class);
});

/*
 * From the field study (docs/adr/0002-defer-gate-registration.md): Truss could
 * not boot in October CMS, which ships its own auth and does not bind Laravel's
 * Gate contract. Every artisan command died, including truss:doctor, which is
 * documented as safe for CI and never consults the gate.
 *
 * Testbench always binds the contract, which is exactly why the whole suite
 * passed while this was broken in the wild. The binding is removed here on
 * purpose.
 */
it('boots when the host application does not bind the Gate contract', function () {
    $app = app();

    unset($app[GateContract::class]);
    Gate::clearResolvedInstances();

    try {
        (new TrussServiceProvider($app))->packageBooted();
    } catch (Throwable $e) {
        $this->fail('Truss must boot without the Gate contract: '.$e->getMessage());
    }

    expect(app()->bound(GateContract::class))->toBeFalse();
});
