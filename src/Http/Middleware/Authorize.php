<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards both Truss routes. Two independent checks, in order:
 *
 *   1. `truss.enabled` — when off, the routes behave as if they do not exist
 *      (404), matching the Telescope/Horizon convention of being invisible
 *      outside the environments where they are switched on.
 *   2. the fixed `viewTruss` gate — 403 when it denies. The ability name is not
 *      configurable; the host app customizes *who* may view via the callback.
 */
class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('truss.enabled'), 404);
        abort_unless(Gate::allows('viewTruss'), 403);

        return $next($request);
    }
}
