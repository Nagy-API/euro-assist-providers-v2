<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account that still holds a temporary password (users.must_change_password, ADR-019) can do
 * nothing except change that password or sign out. Applied to the whole authenticated area and,
 * as persistent middleware, to Livewire update requests, so a direct action call is refused too.
 */
class EnsurePasswordIsChanged
{
    /** Routes a flagged account may still use. */
    private const ALLOWED = ['password.change', 'password.change.update'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password || $request->routeIs(self::ALLOWED)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->headers->has('X-Livewire')) {
            return response()->json(['message' => 'Password change required.'], 403);
        }

        return redirect()->route('password.change');
    }
}
