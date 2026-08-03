<?php

declare(strict_types=1);

use AlbertoArena\Truss\Theme\ThemeStylesheet;

function themeCss(array $config): string
{
    return (new ThemeStylesheet)->build($config);
}

it('emits nothing for an empty or default config', function () {
    expect(themeCss([]))->toBe('')
        ->and(themeCss(['colors' => ['light' => [], 'dark' => []], 'fonts' => ['mono' => null, 'sans' => null]]))->toBe('');
});

it('maps the accent knob onto its two tokens under :root for light', function () {
    $css = themeCss(['colors' => ['light' => ['accent' => '#3730a3']]]);

    expect($css)->toContain(':root {')
        ->and($css)->toContain('--bp-ink: #3730a3;')
        ->and($css)->toContain('--bp-focus-border: #3730a3;');
});

it('maps the border knob onto all four line tokens', function () {
    $css = themeCss(['colors' => ['light' => ['border' => '#123456']]]);

    foreach (['entity-border', 'hair', 'panel-line', 'field-line'] as $token) {
        expect($css)->toContain("--bp-{$token}: #123456;");
    }
});

it('covers every documented knob and only maps known ones', function () {
    $knobs = [
        'accent' => ['ink', 'focus-border'],
        'accent-secondary' => ['cyan'],
        'background' => ['bg', 'edge-bg'],
        'surface' => ['panel', 'entity-bg', 'row-odd', 'field'],
        'surface-alt' => ['row-even'],
        'text' => ['fg', 'entity-text'],
        'muted' => ['muted', 'rel', 'edge'],
        'border' => ['entity-border', 'hair', 'panel-line', 'field-line'],
    ];

    foreach ($knobs as $knob => $tokens) {
        $css = themeCss(['colors' => ['light' => [$knob => '#abcdef']]]);
        foreach ($tokens as $token) {
            expect($css)->toContain("--bp-{$token}: #abcdef;");
        }
    }
});

it('themes the diagram chrome, not just tables, so a custom palette leaves nothing at the default', function () {
    // Regression guard: relationship lines/labels (rel, edge, edge-bg), odd rows,
    // and inputs used to stay on the Blueprint default, so a custom theme rendered
    // a half-themed diagram (blue lines and dark-mode rows on a warm palette).
    $css = themeCss(['colors' => ['light' => [
        'muted' => '#777777',       // drives rel + edge (relationship lines and labels)
        'background' => '#eeeeee',  // drives edge-bg (label backdrop)
        'surface' => '#ffffff',     // drives row-odd + field (odd rows, inputs)
    ]]]);

    foreach (['rel', 'edge'] as $line) {
        expect($css)->toContain("--bp-{$line}: #777777;");
    }
    expect($css)->toContain('--bp-edge-bg: #eeeeee;')
        ->and($css)->toContain('--bp-row-odd: #ffffff;')
        ->and($css)->toContain('--bp-field: #ffffff;');
});

it('derives a faint background grid from the accent colour', function () {
    // The grid is a translucent tint of the accent, so it follows a custom
    // accent instead of staying on the shipped blue.
    $css = themeCss(['colors' => ['light' => ['accent' => '#112233'], 'dark' => ['accent' => '#aabbcc']]]);

    expect($css)->toContain('--bp-grid: rgba(17, 34, 51, 0.07);')
        ->and($css)->toContain('--bp-grid-strong: rgba(17, 34, 51, 0.12);')
        ->and($css)->toContain('--bp-grid: rgba(170, 187, 204, 0.07);');
});

it('accepts a short #rgb accent for the grid derivation', function () {
    expect(themeCss(['colors' => ['light' => ['accent' => '#abc']]]))
        ->toContain('--bp-grid: rgba(170, 187, 204, 0.07);');
});

it('skips the grid derivation when the accent is not a hex colour', function () {
    // rgb()/hsl()/keyword accents are valid, but the grid tint needs channels.
    $css = themeCss(['colors' => ['light' => ['accent' => 'rebeccapurple']]]);

    expect($css)->toContain('--bp-ink: rebeccapurple;')
        ->and($css)->not->toContain('--bp-grid:');
});

it('places dark overrides under the media query and the forced-dark selector', function () {
    $css = themeCss(['colors' => ['dark' => ['background' => '#0b1020']]]);

    expect($css)->toContain('@media (prefers-color-scheme: dark)')
        ->and($css)->toContain(':root:not([data-theme="light"])')
        ->and($css)->toContain(':root[data-theme="dark"]')
        // the token appears in both dark blocks, never in a bare light :root block
        ->and(substr_count($css, '--bp-bg: #0b1020;'))->toBe(2);
});

it('emits font families on --bp-mono and --bp-sans in :root', function () {
    $css = themeCss(['fonts' => ['mono' => 'Fira Code, monospace', 'sans' => 'Inter, system-ui, sans-serif']]);

    expect($css)->toContain('--bp-mono: Fira Code, monospace;')
        ->and($css)->toContain('--bp-sans: Inter, system-ui, sans-serif;')
        ->and($css)->toContain(':root {');
});

it('drops an unknown knob without emitting anything for it', function () {
    $css = themeCss(['colors' => ['light' => ['accent' => '#111111', 'bogus' => '#222222']]]);

    expect($css)->toContain('--bp-ink: #111111;')
        ->and($css)->not->toContain('#222222')
        ->and($css)->not->toContain('bogus');
});

it('accepts the full range of valid colour forms', function () {
    $valid = [
        '#abc', '#abcd', '#a1b2c3', '#a1b2c3d4',
        'rgb(10, 20, 30)', 'rgba(10,20,30,0.5)',
        'hsl(210, 50%, 40%)', 'hsla(210,50%,40%,.5)',
        'rebeccapurple', 'transparent', 'currentColor',
    ];

    foreach ($valid as $value) {
        $css = themeCss(['colors' => ['light' => ['accent' => $value]]]);
        expect($css)->toContain("--bp-ink: {$value};");
    }
});

it('rejects colour values that could break out of the declaration', function () {
    $injections = [
        'red; } body { display:none',
        '#fff; background:url(https://evil.test/x)',
        'blue /* comment */',
        "red\n}",
        'url(javascript:alert(1))',
        '#fff@import',
        'var(--x)',
    ];

    foreach ($injections as $value) {
        $css = themeCss(['colors' => ['light' => ['accent' => $value]]]);
        expect($css)->toBe('', "expected injection '{$value}' to be rejected");
    }
});

it('rejects a font value with dangerous characters and keeps a safe one', function () {
    expect(themeCss(['fonts' => ['mono' => 'Evil; } body{}']]))->toBe('')
        ->and(themeCss(['fonts' => ['sans' => 'url(x)']]))->toBe('');

    expect(themeCss(['fonts' => ['sans' => "'My Font', Arial, sans-serif"]]))
        ->toContain("--bp-sans: 'My Font', Arial, sans-serif;");
});

it('is deterministic and skips only the invalid values in a mixed palette', function () {
    $config = ['colors' => ['light' => ['accent' => '#123123', 'background' => 'red; evil']]];
    $css = themeCss($config);

    expect($css)->toBe(themeCss($config)) // stable
        ->and($css)->toContain('--bp-ink: #123123;')
        ->and($css)->not->toContain('--bp-bg:'); // the bad background is dropped, the good accent stays
});

it('reports whether a custom theme is configured', function () {
    $sheet = new ThemeStylesheet;

    expect($sheet->isConfigured([]))->toBeFalse()
        ->and($sheet->isConfigured(['fonts' => ['mono' => null, 'sans' => null]]))->toBeFalse()
        ->and($sheet->isConfigured(['colors' => ['light' => ['accent' => '#000']]]))->toBeTrue()
        ->and($sheet->isConfigured(['fonts' => ['sans' => 'Inter']]))->toBeTrue();
});
