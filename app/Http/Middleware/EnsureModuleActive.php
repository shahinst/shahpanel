<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a route with 404 unless the given module is active. Used to make a
 * whole feature (e.g. the payment section) disappear when its module is off.
 *
 * Usage: ->middleware('module:payments')
 */
class EnsureModuleActive
{
    public function handle(Request $request, Closure $next, string $slug): Response
    {
        abort_unless(module_active($slug), 404);

        return $next($request);
    }
}
