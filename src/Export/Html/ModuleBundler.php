<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Export\Html;

use RuntimeException;

/**
 * Flattens the frontend's ES module graph into one self-contained entry source,
 * by rewriting every relative import specifier to a `data:` URL holding the
 * module it names.
 *
 * Why this exists rather than a bundler: the package ships its assets with no
 * build step (see CLAUDE.md), so there is no esbuild or rollup to reach for, and
 * an exported HTML file opened over file:// has no siblings for `./selection.js`
 * to resolve against.
 *
 * Why not an import map, which is the obvious answer: a module fetched from a
 * `data:` URL has no base URL, so a relative specifier *inside* it cannot
 * resolve at all. The graph has such an edge today (mermaid-definition.js
 * imports type-labels.js), so an import map would break on the first export.
 * Rewriting bottom-up means a nested module's own imports are already absolute
 * by the time it is encoded.
 *
 * One property to know: a module imported by two others is encoded into each of
 * them, so it is duplicated and instantiated twice. The package's modules are
 * pure (they export functions, not mutable state), and nothing in the real graph
 * is imported twice today, but a module holding module-level state would not
 * behave as it does on the dashboard.
 */
class ModuleBundler
{
    /**
     * Matches the three import forms the frontend uses, and the dynamic one it
     * does not, so a future `import('./x.js')` is carried rather than silently
     * left relative:
     *
     *   import { a } from './x.js'   export { a } from './x.js'
     *   import './x.js'              import('./x.js')
     *
     * Anchored on `from` or `import` on purpose. A bare quoted './x.js' string
     * elsewhere in a module is a string literal, not an import, and rewriting it
     * would corrupt the source.
     */
    private const SPECIFIER = '/(?P<prefix>\b(?:from|import)\s*\(?\s*)(?P<quote>[\'"])\.\/(?P<name>[A-Za-z0-9._-]+\.js)(?P=quote)/';

    /** @var array<string, string> memoised data URLs, keyed by module name */
    private array $encoded = [];

    /**
     * @param  string  $entry  module name, as it appears in $sources
     * @param  array<string, string>  $sources  module name => source code
     * @return string the entry's source with every relative specifier rewritten
     */
    public function bundle(string $entry, array $sources): string
    {
        $this->encoded = [];

        return $this->rewrite($this->sourceFor($entry, $sources), $sources, [$entry]);
    }

    /**
     * @param  array<string, string>  $sources
     * @param  list<string>  $stack  the import chain, for cycle detection
     */
    private function rewrite(string $source, array $sources, array $stack): string
    {
        return preg_replace_callback(
            self::SPECIFIER,
            function (array $match) use ($sources, $stack): string {
                $url = $this->encode($match['name'], $sources, $stack);

                return $match['prefix'].$match['quote'].$url.$match['quote'];
            },
            $source,
        );
    }

    /**
     * @param  array<string, string>  $sources
     * @param  list<string>  $stack
     */
    private function encode(string $name, array $sources, array $stack): string
    {
        if (in_array($name, $stack, true)) {
            throw new RuntimeException(
                'Circular import in the frontend module graph: '.implode(' -> ', [...$stack, $name]).'.',
            );
        }

        // Memoised for the bytes, not for the recursion: the same module reached
        // twice must encode to the same URL or the output stops being byte-stable.
        if (isset($this->encoded[$name])) {
            return $this->encoded[$name];
        }

        $rewritten = $this->rewrite($this->sourceFor($name, $sources), $sources, [...$stack, $name]);

        return $this->encoded[$name] = 'data:text/javascript;base64,'.base64_encode($rewritten);
    }

    /**
     * @param  array<string, string>  $sources
     */
    private function sourceFor(string $name, array $sources): string
    {
        if (! isset($sources[$name])) {
            throw new RuntimeException(
                "The frontend module [{$name}] is imported but was not provided to the bundler. ".
                'Add it to the AssetController allow-list.',
            );
        }

        return $sources[$name];
    }
}
