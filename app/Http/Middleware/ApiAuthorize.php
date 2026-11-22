<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Http\Controllers\Api\Support\ApiPrincipal;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides who may read provider data through the API.
 *
 * - config('providers.api.public_read') = false (default, decision D7 pending): an authenticated
 *   principal with the providers.view permission AND a token carrying the providers:read ability
 *   is required.
 * - true: anonymous reads are allowed; a presented token must still be valid (ApiAuthenticate)
 *   and carry the ability.
 */
class ApiAuthorize
{
    public const ABILITY = 'providers:read';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            if (config('providers.api.public_read')) {
                return $next($request);
            }

            throw new AuthenticationException('Unauthenticated.', ['sanctum']);
        }

        if (! $user instanceof ApiPrincipal
            || ! $user->tokenCan(self::ABILITY)
            || ! Gate::forUser($user)->allows(Permission::ProvidersView->value)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $next($request);
    }
}
