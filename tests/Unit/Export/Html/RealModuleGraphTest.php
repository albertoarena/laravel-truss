<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\Html\ModuleBundler;
use AlbertoArena\Truss\Http\Controllers\AssetController;

/**
 * The bundler against the graph the package actually ships, sourced from the
 * dashboard's own asset allow-list rather than from a glob. That coupling is the
 * point: a module added to resources/js and forgotten in the allow-list fails
 * here, loudly, instead of 404ing in somebody's browser.
 */
function allowListedModules(): array
{
    $sources = [];

    foreach (AssetController::assets() as $name => $path) {
        if (str_ends_with($name, '.js') && $name !== 'mermaid.min.js') {
            $sources[$name] = file_get_contents(dirname(__DIR__, 4).'/resources/'.$path);
        }
    }

    return $sources;
}

it('bundles the real frontend graph with no relative specifier left', function () {
    $bundled = (new ModuleBundler)->bundle('truss.js', allowListedModules());

    expect($bundled)->not->toContain("from './")
        ->and($bundled)->not->toContain("import './")
        ->and($bundled)->toContain('data:text/javascript;base64,');
});

it('reaches every module truss.js imports, including the nested one', function () {
    $bundled = (new ModuleBundler)->bundle('truss.js', allowListedModules());

    preg_match_all('/data:text\/javascript;base64,([A-Za-z0-9+\/=]+)/', $bundled, $matches);
    $inlined = implode("\n", array_map(base64_decode(...), $matches[1]));

    // type-labels.js is reachable only through mermaid-definition.js, so its
    // presence is the evidence that the walk is recursive rather than one level
    // deep. Its own source is inside another module's base64, so assert on the
    // decoded text rather than on the document.
    expect($inlined)->toContain('generateErDiagram')
        ->and($inlined.$bundled)->toContain('typeLabel');
});
