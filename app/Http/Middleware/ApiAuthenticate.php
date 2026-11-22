<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\Support\ApiPrincipal;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stateless bearer-token authentication for /api/v2 (Laravel Sanctum personal access tokens).
 *
 * - Session/cookie authentication is never honoured on the API (Sanctum's stateful "web" guard
 *   lookup is disabled), so the API cannot be driven by a browser session (no CSRF surface).
 * - A request that presents a bearer token must carry a valid, unexpired token of an ACTIVE
 *   account; otherwise it is rejected with 401 even if anonymous reads are allowed.
 * - A request without a token continues anonymously; ApiAuthorize decides whether that is allowed.
 *
 * It runs before the rate limiter so throttling is keyed by the authenticated principal.
 */
class ApiAuthenticate implements AuthenticatesRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        config(['sanctum.guard' => []]);
        Auth::shouldUse('sanctum');

        $bearer = $request->bearerToken();
        if ($bearer === null || $bearer === '') {
            return $next($request);
        }

        $user = Auth::guard('sanctum')->user();

        if (! $user instanceof ApiPrincipal || ! $user->is_active) {
            throw new AuthenticationException('Unauthenticated.', ['sanctum']);
        }

        return $next($request);
    }
}
