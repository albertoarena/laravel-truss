<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\Html\AssetInliner;

it('inlines the three font faces as data URIs', function () {
    $css = (new AssetInliner)->css();

    // Relative url() in an inlined <style> resolves against the document, so on a
    // file:// page it silently falls back to a system font. That is how the
    // Firefox label clipping fixed in v1.10 comes back: the diagram measures in
    // one face and paints in another.
    expect($css)->not->toContain('url("ibm-plex-mono-400.woff2")')
        ->and(substr_count($css, 'url("data:font/woff2;base64,'))->toBe(3);
});

it('leaves no relative asset reference in the stylesheet', function () {
    $css = (new AssetInliner)->css();

    // Counted rather than pattern-matched on purpose: the obvious negative regex
    // has an optional-quote group, which lets the lookahead pass on the very
    // data: URIs it is meant to allow, so it reports clean whatever the input.
    expect(substr_count($css, 'url('))->toBe(substr_count($css, 'url("data:'))
        ->and(substr_count($css, 'url('))->toBeGreaterThan(0);
});

it('reads the vendored mermaid, not a CDN copy', function () {
    expect((new AssetInliner)->mermaid())->toContain('mermaid')
        ->and(strlen((new AssetInliner)->mermaid()))->toBeGreaterThan(1_000_000);
});

it('bundles the frontend modules into one source with no relative specifier', function () {
    $js = (new AssetInliner)->modules();

    expect($js)->not->toContain("from './")
        ->and($js)->toContain('data:text/javascript;base64,');
});

it('is deterministic across calls', function () {
    $a = new AssetInliner;
    $b = new AssetInliner;

    expect($a->css())->toBe($b->css())
        ->and($a->modules())->toBe($b->modules());
});
