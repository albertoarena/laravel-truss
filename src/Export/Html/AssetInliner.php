<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Export\Html;

use AlbertoArena\Truss\Http\Controllers\AssetController;
use RuntimeException;

/**
 * Reads the package's own frontend assets and returns them in a form that can be
 * embedded in a single HTML document: no routes, no relative URLs, nothing for a
 * browser to fetch.
 *
 * The inventory comes from the dashboard's asset allow-list rather than from a
 * glob of `resources/`, so the exported file and the served dashboard can never
 * disagree about which files make up the frontend.
 */
class AssetInliner
{
    private readonly string $resources;

    public function __construct(?string $resources = null)
    {
        $this->resources = rtrim($resources ?? dirname(__DIR__, 3).'/resources', '/').'/';
    }

    /**
     * The stylesheet with every font face embedded as a data URI.
     *
     * `truss.css` references its faces relatively, which resolves against the
     * stylesheet's own URL when the dashboard serves it from the asset route.
     * Inlined into a <style> block it would resolve against the document
     * instead, so on a file:// page the faces silently fall back to a system
     * font. That is not cosmetic: the diagram measures label widths in one face
     * and paints in another, which is the Firefox clipping bug v1.10 fixed.
     */
    public function css(): string
    {
        return preg_replace_callback(
            '/url\(["\']?([A-Za-z0-9._-]+\.woff2)["\']?\)/',
            fn (array $match): string => 'url("data:font/woff2;base64,'.base64_encode($this->read('fonts/'.$match[1])).'")',
            $this->read('css/truss.css'),
        );
    }

    /** The vendored Mermaid, as shipped. Never a CDN copy. */
    public function mermaid(): string
    {
        return $this->read('js/vendor/mermaid.min.js');
    }

    /** The frontend module graph flattened into one entry source. */
    public function modules(): string
    {
        $sources = [];

        foreach (AssetController::assets() as $name => $path) {
            if (str_ends_with($name, '.js') && $name !== 'mermaid.min.js') {
                $sources[$name] = $this->read($path);
            }
        }

        return (new ModuleBundler)->bundle('truss.js', $sources);
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($this->resources.$path);

        if ($contents === false) {
            throw new RuntimeException("Truss asset [{$path}] could not be read from [{$this->resources}].");
        }

        return $contents;
    }
}
