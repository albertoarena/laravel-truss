<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Theme;

/**
 * Builds the optional custom-theme stylesheet from the `truss.theme` config.
 *
 * The public API is a small set of semantic knobs (accent, background, text, and
 * so on); each maps onto one or more of the dashboard's internal `--bp-*` CSS
 * custom properties. Only the knobs a host actually sets are emitted, so the
 * shipped Blueprint default shows through everywhere else, and a handful of
 * values re-skins the whole dashboard coherently in both light and dark.
 *
 * This is pure presentation and is delivered as a same-origin stylesheet (see
 * ThemeController), so the package keeps its `style-src 'self'` CSP with no
 * inline styles. Because config values are written verbatim into a CSS response,
 * every value is validated against a strict allow-list first: a value that could
 * break out of the declaration is dropped (never emitted), so the sheet can never
 * be broken or injected, only degraded to the default.
 */
class ThemeStylesheet
{
    /**
     * Semantic knob => the internal `--bp-*` tokens it drives (prefix dropped).
     * The knob names are the public, stable contract; the token targets are
     * private and free to change. Iterated in this order for deterministic output.
     *
     * @var array<string, list<string>>
     */
    private const KNOBS = [
        'accent' => ['ink', 'focus-border'],
        'accent-secondary' => ['cyan'],
        'background' => ['bg', 'edge-bg'],
        'surface' => ['panel', 'entity-bg', 'row-odd', 'field'],
        'surface-alt' => ['row-even'],
        'text' => ['fg', 'entity-text'],
        'muted' => ['muted', 'rel', 'edge'],
        'border' => ['entity-border', 'hair', 'panel-line', 'field-line'],
    ];

    /**
     * Accepts hex (#rgb/#rgba/#rrggbb/#rrggbbaa), rgb()/rgba()/hsl()/hsla() with
     * numeric args only, and a bare colour keyword (named colour, transparent,
     * currentColor). Anchored and character-restricted, so nothing containing
     * `;`, `{`, `}`, `@`, a comment, `url(`, `var(`, or a newline can pass.
     */
    private const COLOR = '/^(#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})|(?:rgb|rgba|hsl|hsla)\([0-9.,%\s\/]+\)|[a-z]+)$/i';

    /**
     * A font-family value: family names (quoted or bare), commas, spaces, dots,
     * and hyphens. Rejects any character that could break out of the declaration.
     */
    private const FONT = '/^[A-Za-z0-9\s,\'".\-]+$/';

    /**
     * @param  array<string, mixed>  $config  the `truss.theme` config array
     */
    public function build(array $config): string
    {
        $root = [
            ...$this->fontDeclarations((array) ($config['fonts'] ?? [])),
            ...$this->colorDeclarations((array) ($config['colors']['light'] ?? [])),
        ];
        $dark = $this->colorDeclarations((array) ($config['colors']['dark'] ?? []));

        $blocks = [];
        if ($root !== []) {
            $blocks[] = $this->rule(':root', $root);
        }
        if ($dark !== []) {
            // Mirror truss.css exactly: auto-dark via the media query (excluding a
            // forced-light root), and forced-dark via the data-theme selector, so
            // custom overrides follow the light/dark toggle just like the defaults.
            $blocks[] = "@media (prefers-color-scheme: dark) {\n".$this->rule(':root:not([data-theme="light"])', $dark, 1)."\n}";
            $blocks[] = $this->rule(':root[data-theme="dark"]', $dark);
        }

        return $blocks === [] ? '' : implode("\n", $blocks)."\n";
    }

    /**
     * Whether a custom theme is configured at all (any non-empty font or colour),
     * so the shell can skip the theme <link> entirely on a default install.
     *
     * @param  array<string, mixed>  $config
     */
    public function isConfigured(array $config): bool
    {
        $any = static fn (array $values): bool => array_filter(
            $values,
            static fn ($v): bool => $v !== null && $v !== '',
        ) !== [];

        return $any((array) ($config['fonts'] ?? []))
            || $any((array) ($config['colors']['light'] ?? []))
            || $any((array) ($config['colors']['dark'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $knobs
     * @return list<string>
     */
    private function colorDeclarations(array $knobs): array
    {
        $decls = [];

        foreach (self::KNOBS as $knob => $tokens) {
            if (! array_key_exists($knob, $knobs)) {
                continue;
            }
            $value = $this->sanitizeColor($knobs[$knob]);
            if ($value === null) {
                continue;
            }
            foreach ($tokens as $token) {
                $decls[] = "--bp-{$token}: {$value};";
            }
        }

        // The background grid is a translucent tint of the accent, so it follows
        // a custom accent rather than staying on the shipped blue. This needs the
        // colour channels, so it applies only when the accent is a hex value; an
        // rgb()/hsl()/keyword accent leaves the grid on the default.
        $rgb = isset($knobs['accent']) ? $this->hexToRgb($this->sanitizeColor($knobs['accent'])) : null;
        if ($rgb !== null) {
            $decls[] = "--bp-grid: rgba({$rgb}, 0.07);";
            $decls[] = "--bp-grid-strong: rgba({$rgb}, 0.12);";
        }

        return $decls;
    }

    /**
     * The "r, g, b" channels of a hex colour, or null if it is not hex (a short
     * or long hex, with or without an alpha byte, which is dropped).
     */
    private function hexToRgb(?string $value): ?string
    {
        if ($value === null || preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value) !== 1) {
            return null;
        }

        $hex = ltrim($value, '#');
        if (strlen($hex) <= 4) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return hexdec(substr($hex, 0, 2)).', '.hexdec(substr($hex, 2, 2)).', '.hexdec(substr($hex, 4, 2));
    }

    /**
     * @param  array<string, mixed>  $fonts
     * @return list<string>
     */
    private function fontDeclarations(array $fonts): array
    {
        $decls = [];

        foreach (['mono', 'sans'] as $key) {
            $raw = $fonts[$key] ?? null;
            if ($raw === null || $raw === '' || ! is_string($raw)) {
                continue;
            }
            $value = $this->sanitizeFont($raw);
            if ($value !== null) {
                $decls[] = "--bp-{$key}: {$value};";
            }
        }

        return $decls;
    }

    private function sanitizeColor(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return preg_match(self::COLOR, $value) === 1 ? $value : null;
    }

    private function sanitizeFont(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' && preg_match(self::FONT, $value) === 1 ? $value : null;
    }

    /**
     * @param  list<string>  $decls
     */
    private function rule(string $selector, array $decls, int $indent = 0): string
    {
        $pad = str_repeat('  ', $indent);
        $body = implode("\n", array_map(fn (string $d): string => "{$pad}  {$d}", $decls));

        return "{$pad}{$selector} {\n{$body}\n{$pad}}";
    }
}
