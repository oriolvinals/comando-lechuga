<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hides god-only pages: 404 (not 403, so nothing reveals the page exists)
 * unless HandleGodMode switched god mode on for this request.
 */
class EnsureGodMode
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!HandleGodMode::isEnabled($request)) {
            abort(404);
        }

        return $next($request);
    }
}
