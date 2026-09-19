<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\Html\ModuleBundler;

/**
 * A three-level fixture graph, deliberately not the real one: a root importing a
 * leaf and a middle module, and the middle module importing the same leaf. The
 * real graph has exactly one nested edge today (mermaid-definition imports
 * type-labels), and a bundler that only handles that shape would break silently
 * the day somebody adds a second.
 */
function fixtureGraph(): array
{
    return [
        'leaf.js' => "export const leaf = 1;\n",
        'middle.js' => "import { leaf } from './leaf.js';\nexport const middle = leaf + 1;\n",
        'root.js' => "import { leaf } from './leaf.js';\nimport { middle } from './middle.js';\nconsole.log(leaf, middle);\n",
    ];
}

it('rewrites every relative specifier to a data URL', function () {
    $bundled = (new ModuleBundler)->bundle('root.js', fixtureGraph());

    expect($bundled)->not->toContain("'./")
        ->and($bundled)->not->toContain('"./')
        ->and(substr_count($bundled, 'data:text/javascript;base64,'))->toBe(2);
});

it('carries a nested import through, so a module loaded from a data URL never resolves a relative path', function () {
    $bundled = (new ModuleBundler)->bundle('root.js', fixtureGraph());

    preg_match_all('/data:text\/javascript;base64,([A-Za-z0-9+\/=]+)/', $bundled, $matches);
    $decoded = array_map(base64_decode(...), $matches[1]);

    // The middle module is in there, and its own import of the leaf was rewritten
    // rather than left relative. That edge is the whole reason an import map cannot
    // do this job.
    $middle = collect($decoded)->first(fn (string $source) => str_contains($source, 'middle'));

    expect($middle)->not->toBeNull()
        ->and($middle)->not->toContain('./leaf.js')
        ->and($middle)->toContain('data:text/javascript;base64,');
});

it('is deterministic: the same graph twice gives the same bytes', function () {
    $bundler = new ModuleBundler;

    expect($bundler->bundle('root.js', fixtureGraph()))
        ->toBe($bundler->bundle('root.js', fixtureGraph()));
});

it('raises on a circular import rather than recursing forever', function () {
    (new ModuleBundler)->bundle('a.js', [
        'a.js' => "import './b.js';\n",
        'b.js' => "import './a.js';\n",
    ]);
})->throws(RuntimeException::class, 'Circular import');

it('raises when an imported module was not provided, naming the allow-list', function () {
    (new ModuleBundler)->bundle('root.js', [
        'root.js' => "import './missing.js';\n",
    ]);
})->throws(RuntimeException::class, 'missing.js');
