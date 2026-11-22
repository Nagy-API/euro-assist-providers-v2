<?php

use App\Http\Controllers\Api\FeaturedProviderController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\ProviderTypeController;
use App\Http\Middleware\ApiAuthenticate;
use App\Http\Middleware\ApiAuthorize;
use App\Http\Middleware\ApiIpRateLimit;
use Illuminate\Support\Facades\Route;

// Versioned REST API. All routes live under /api/v2 (see docs/06_API_CONTRACT.md and
// docs/openapi/v2.yaml). Read-only by design: there are intentionally NO write endpoints.
//
// Middleware order matters: per-IP ceiling (also bounds invalid-token guessing, which fails inside
// authentication) -> authenticate (bearer token) -> rate limit (keyed by principal, else IP) ->
// authorize (public_read flag / providers.view + providers:read ability).
Route::prefix('v2')->name('api.v2.')
    ->middleware([ApiIpRateLimit::class, ApiAuthenticate::class, 'throttle:api-v2', ApiAuthorize::class])
    ->group(function () {
        Route::get('providers', [ProviderController::class, 'index'])->name('providers.index');
        Route::get('providers/names', [ProviderController::class, 'names'])->name('providers.names');
        Route::get('providers/coordinates', [ProviderController::class, 'coordinates'])->name('providers.coordinates');
        Route::get('providers/distribution', [ProviderController::class, 'distribution'])->name('providers.distribution');
        Route::get('providers/{provider}', [ProviderController::class, 'show'])->whereNumber('provider')->name('providers.show');

        Route::get('provider-types', [ProviderTypeController::class, 'index'])->name('provider-types.index');
        Route::get('featured-providers', [FeaturedProviderController::class, 'index'])->name('featured-providers.index');
    });
