<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Severity;

it('ranks error above warning above info', function () {
    expect(Severity::Error->rank())->toBeGreaterThan(Severity::Warning->rank());
    expect(Severity::Warning->rank())->toBeGreaterThan(Severity::Info->rank());
});

it('reports whether it meets or exceeds a fail threshold', function () {
    expect(Severity::Error->meetsOrExceeds(Severity::Warning))->toBeTrue();
    expect(Severity::Warning->meetsOrExceeds(Severity::Warning))->toBeTrue();
    expect(Severity::Info->meetsOrExceeds(Severity::Warning))->toBeFalse();
});

it('has stable string values for config and output', function () {
    expect(Severity::Error->value)->toBe('error');
    expect(Severity::from('warning'))->toBe(Severity::Warning);
});
