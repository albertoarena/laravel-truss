<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Http\Controllers;

use AlbertoArena\Truss\Cache\SchemaCacheRepository;
use Illuminate\Contracts\View\View;

/**
 * Renders the Blade shell: layout, connection switcher, and the container the
 * frontend fetches into. The schema itself is loaded client-side from the JSON
 * endpoint (see SchemaApiController) — this route serves no schema data. The one
 * thing it does pass through is the list of visualizable connections, so the
 * switcher can offer them without a second request.
 */
class IndexController
{
    public function __invoke(SchemaCacheRepository $cache): View
    {
        return view('truss::index', [
            'connections' => $cache->managedConnections(),
        ]);
    }
}
