<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the dashboard's static assets from the package itself, Telescope-style,
 * so no `vendor:publish` step is needed and there is no CDN dependency (Mermaid
 * is vendored and served from here too). Requests are allow-listed by basename —
 * only the known files map to a path, which is also what makes path traversal
 * impossible. The route sits inside the gated group, so unauthorized users get
 * 404 on the assets exactly as they do on the page (see docs/DECISIONS.md).
 */
class AssetController
{
    /**
     * Public asset name → path relative to the package `resources/` directory.
     *
     * @var array<string, string>
     */
    private const ASSETS = [
        'truss.js' => 'js/truss.js',
        'selection.js' => 'js/selection.js',
        'mermaid-definition.js' => 'js/mermaid-definition.js',
        'type-labels.js' => 'js/type-labels.js',
        'viewport.js' => 'js/viewport.js',
        'mermaid.min.js' => 'js/vendor/mermaid.min.js',
        'truss.css' => 'css/truss.css',
    ];

    public function __invoke(string $file): BinaryFileResponse
    {
        $relative = self::ASSETS[$file] ?? abort(Response::HTTP_NOT_FOUND);

        $path = dirname(__DIR__, 3).'/resources/'.$relative;
        abort_unless(is_file($path), Response::HTTP_NOT_FOUND);

        return response()->file($path, [
            'Content-Type' => $this->contentType($file),
            // Gated per-user, so keep it out of shared caches. In debug (local
            // dev of the package itself) never cache, so edits show on refresh;
            // otherwise a day is plenty and a package upgrade changes the file.
            'Cache-Control' => config('app.debug') ? 'no-store' : 'private, max-age=86400',
        ]);
    }

    private function contentType(string $file): string
    {
        return str_ends_with($file, '.css') ? 'text/css' : 'text/javascript';
    }
}
