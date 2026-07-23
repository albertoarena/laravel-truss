<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Renders the Blade shell: layout, connection switcher, and the container the
 * frontend fetches into. The schema itself is loaded client-side from the JSON
 * endpoint (see SchemaApiController) — this route serves no data.
 */
class IndexController
{
    public function __invoke(): View
    {
        return view('truss::index');
    }
}
