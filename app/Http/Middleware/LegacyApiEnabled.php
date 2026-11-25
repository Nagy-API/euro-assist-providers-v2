<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Kill switch for the public legacy-compatible read API (providers.legacy_api.enabled). */
class LegacyApiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('providers.legacy_api.enabled'), 404);

        return $next($request);
    }
}
