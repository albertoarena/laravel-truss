<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Http\Middleware;

use AlbertoArena\Truss\TrussServiceProvider;
use Closure;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
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
            // An application that binds no Gate cannot answer "may this person
            // view Truss", and outside local the answer has to be no. Checked
            // rather than left to fail: resolving an unbound contract raises an
            // error page, and an error page confirms the route exists, which is
            // the one thing this middleware is written to avoid.
            abort_unless(app()->bound(GateContract::class), 404);

            // Define it here rather than relying on boot: the provider skips
            // registration when the host binds no Gate contract, and a host may
            // bind one only once a request is being handled. By this point a
            // request exists, so this is the first moment the gate is genuinely
            // needed. See docs/adr/0002-defer-gate-registration.md.
            TrussServiceProvider::defineGate();

            abort_unless(Gate::allows('viewTruss'), 404);
        }

        return $next($request);
    }
}
