<?php

/*
 * Sanctum is used ONLY for stateless /api/v2 personal access tokens (see ApiAuthenticate).
 * - No SPA/cookie (stateful) authentication: `stateful` and `guard` are empty, so a browser
 *   session can never drive the API.
 * - `routes` is false: the /sanctum/csrf-cookie endpoint is not needed and is not exposed.
 * - Expiry is set per token (`expires_at`, providers.api.token_ttl_days) by `php artisan api:token`.
 */
return [
    'stateful' => [],
    'guard' => [],
    'routes' => false,
    'expiration' => null,
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
];
