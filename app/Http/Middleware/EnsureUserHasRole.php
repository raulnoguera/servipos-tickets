<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Restringe una ruta a ciertos roles: ->middleware('role:admin,operator'). */
class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->active || ! $user->hasRole(...array_map(Role::from(...), $roles))) {
            abort(403, 'No tiene permisos para esta acción.');
        }

        return $next($request);
    }
}
