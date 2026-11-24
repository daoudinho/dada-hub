<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse l'accès à un module que l'administrateur n'a pas ouvert au compte connecté.
 */
class EnsureModuleAccess
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($request->user()?->canAccessModule($module), 403, 'Ce module ne vous est pas ouvert.');

        return $next($request);
    }
}
