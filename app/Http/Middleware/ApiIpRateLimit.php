<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-IP request ceiling for /api/v2 that runs BEFORE bearer-token authentication.
 *
 * Laravel's middleware priority always moves `throttle` after authentication middleware, so a
 * request with an invalid token (rejected inside ApiAuthenticate) would never be counted. This
 * middleware is registered in the priority list before AuthenticatesRequests (bootstrap/app.php).
 */
class ApiIpRateLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'api-v2-ip:'.$request->ip();
        $max = max(1, (int) config('providers.api.ip_rate_limit_per_minute', 300));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $retryAfter = RateLimiter::availableIn($key);
            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $max,
                'X-RateLimit-Remaining' => 0,
            ]);
        }
        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
