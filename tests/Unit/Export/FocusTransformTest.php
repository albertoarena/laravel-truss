<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\FocusTransform;

/**
 * The PHP half of the shared focus fixture. The JS half is
 * tests/js/focus-fixture.test.js; both assert the same subsets so the export
 * focus and the interactive dashboard focus agree.
 */
function focusFixture(): array
{
    $path = dirname(__DIR__, 2).'/Fixtures/export/focus-cases.json';

    return json_decode((string) file_get_contents($path), true);
}

it('agrees with the shared focus fixture on every case', function () {
    $fixture = focusFixture();

    foreach ($fixture['cases'] as $case) {
        $result = (new FocusTransform)->apply($fixture['tables'], $case['root'], $case['depth']);

        expect(array_column($result, 'name'))
            ->toBe($case['expected'], "focus({$case['root']}, depth: {$case['depth']})");
    }
});

it('returns the root alone at depth 0', function () {
    $fixture = focusFixture();

    $result = (new FocusTransform)->apply($fixture['tables'], 'posts', 0);

    expect(array_column($result, 'name'))->toBe(['posts']);
});

it('raises a clear error when the root table is missing', function () {
    (new FocusTransform)->apply(focusFixture()['tables'], 'ghost', 1);
})->throws(InvalidArgumentException::class, 'Cannot focus on [ghost]');

it('does not add a self-referencing table to its own neighbourhood spuriously', function () {
    // categories has a parent_id self-FK; at depth 1 its only neighbour is posts,
    // which references it. The self-FK must not create a phantom edge.
    $fixture = focusFixture();

    $result = (new FocusTransform)->apply($fixture['tables'], 'categories', 1);

    expect(array_column($result, 'name'))->toBe(['posts', 'categories']);
});
