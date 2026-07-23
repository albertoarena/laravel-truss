<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards both Truss routes:
 *
 *   1. `truss.enabled` — when off, the routes behave as if they do not exist
 *      (404), so it is invisible
 *      outside the environments where they are switched on.
 *   2. the fixed `viewTruss` gate — consulted only in non-local environments
 *      (local is unconditionally open). A denial returns
 *      404, not 403: the dashboard never confirms it exists to someone who may
 *      not view it. The ability name is not configurable; the host app
 *      customizes *who* may view via the gate callback (or the allow-list the
 *      shipped default gate reads).
 */
class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('truss.enabled'), 404);

        if (! app()->environment('local')) {
            abort_unless(Gate::allows('viewTruss'), 404);
        }

        return $next($request);
    }
}
