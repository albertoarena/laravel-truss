<?php

declare(strict_types=1);

use AlbertoArena\Truss\Doctor\Contracts\Rule;
use AlbertoArena\Truss\Doctor\Finding;
use AlbertoArena\Truss\Doctor\FindingCollection;
use AlbertoArena\Truss\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Run one doctor rule and collect its findings as a list, for rule unit tests.
 *
 * @param  array<string, mixed>  $snapshot
 * @return list<Finding>
 */
function doctorCheck(Rule $rule, array $snapshot, string $connection = 'testing'): array
{
    return array_values([...$rule->check($snapshot, $connection)]);
}

/**
 * Normalise whatever a doctor expectation is given (a rule's iterable, a plain
 * array, or a FindingCollection) to a list of findings.
 *
 * @return list<Finding>
 */
function doctorFindingList(mixed $value): array
{
    if ($value instanceof FindingCollection) {
        return $value->all();
    }

    return array_values(is_array($value) ? $value : [...$value]);
}

// expect($findings)->toHaveFinding('TRUSS-INT-001', table: 'logs', column: 'x')
expect()->extend('toHaveFinding', function (string $code, ?string $table = null, ?string $column = null) {
    $matched = false;

    foreach (doctorFindingList($this->value) as $finding) {
        if ($finding->code === $code
            && ($table === null || $finding->table === $table)
            && ($column === null || $finding->column === $column)) {
            $matched = true;
            break;
        }
    }

    $where = $table !== null ? " on {$table}".($column !== null ? ".{$column}" : '') : '';
    expect($matched)->toBeTrue("Expected a {$code} finding{$where}.");

    return $this;
});

// expect($findings)->toBeClean()
expect()->extend('toBeClean', function () {
    expect(doctorFindingList($this->value))->toBe([]);

    return $this;
});
