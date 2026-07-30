<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\RuleRegistry;

/** @param  list<Rule>  $rules */
function ruleCodes(array $rules): array
{
    return array_map(fn (Rule $rule): string => $rule->code(), $rules);
}

it('registers the phase 1 rules by default', function () {
    expect(RuleRegistry::default()->all())->toHaveCount(13);
});

it('recommended runs high-confidence rules only', function () {
    $codes = ruleCodes(RuleRegistry::default()->resolve('recommended'));

    expect($codes)
        ->toContain('TRUSS-INT-001', 'TRUSS-INT-003', 'TRUSS-IDX-001')
        ->not->toContain('TRUSS-INT-002')  // heuristic
        ->not->toContain('TRUSS-IDX-006')  // heuristic
        ->not->toContain('TRUSS-TYP-001'); // heuristic
});

it('strict runs every rule and none runs nothing', function () {
    expect(RuleRegistry::default()->resolve('strict'))->toHaveCount(13);
    expect(RuleRegistry::default()->resolve('none'))->toBe([]);
});

it('only keeps the given categories', function () {
    $codes = ruleCodes(RuleRegistry::default()->resolve('strict', only: ['index']));

    expect($codes)->not->toBeEmpty()->each->toStartWith('TRUSS-IDX');
});

it('skip drops the given categories', function () {
    $codes = ruleCodes(RuleRegistry::default()->resolve('strict', skip: ['type']));

    expect($codes)->not->toContain('TRUSS-TYP-001', 'TRUSS-TYP-002');
});

it('lets an explicit enable include a heuristic rule the preset would skip', function () {
    $codes = ruleCodes(RuleRegistry::default()->resolve('recommended', enabled: ['TRUSS-INT-002' => true]));

    expect($codes)->toContain('TRUSS-INT-002');
});

it('lets an explicit disable remove a rule the preset would include', function () {
    $codes = ruleCodes(RuleRegistry::default()->resolve('recommended', enabled: ['TRUSS-INT-001' => false]));

    expect($codes)->not->toContain('TRUSS-INT-001');
});
