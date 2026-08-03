<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Http\Controllers;

use AlbertoArena\Truss\Theme\ThemeStylesheet;
use Illuminate\Http\Response;

/**
 * Serves the optional custom-theme stylesheet, generated from `truss.theme`, as
 * a same-origin CSS file. Delivering the overrides as a served sheet (rather than
 * an inline <style>) keeps the package's `style-src 'self'` CSP intact.
 *
 * It sits inside the gated route group like the other assets, so an unauthorized
 * user gets 404 here exactly as on the page and it never confirms Truss exists.
 * The body is empty when no custom theme is configured; the shell only links this
 * route when one is (see IndexController), so a default install never fetches it.
 */
class ThemeController
{
    public function __invoke(ThemeStylesheet $stylesheet): Response
    {
        $css = $stylesheet->build((array) config('truss.theme', []));

        return response($css, 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            // Gated per-user, so keep it out of shared caches; never cache in
            // debug so config edits show on refresh, mirroring the asset route.
            'Cache-Control' => config('app.debug') ? 'no-store' : 'private, max-age=86400',
        ]);
    }
}
