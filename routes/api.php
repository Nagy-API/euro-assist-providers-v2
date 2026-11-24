<?php

use App\Http\Controllers\Api\FeaturedProviderController;
use App\Http\Controllers\Api\Legacy\LegacyMediaController;
use App\Http\Controllers\Api\Legacy\LegacyProviderController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\ProviderTypeController;
use App\Http\Middleware\ApiAuthenticate;
use App\Http\Middleware\ApiAuthorize;
use App\Http\Middleware\ApiIpRateLimit;
use App\Http\Middleware\LegacyApiEnabled;
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

// V1-compatible PUBLIC READ-ONLY API (ADR-021, docs/20_LEGACY_API_COMPAT.md). Consumers: the medical-network
// website (providers.php) and mobile clients that read the old /api paths without credentials.
// GET only: the old declared-but-unimplemented store/update/destroy and the broken JWT login are
// deliberately NOT restored. Fixed language per route, per-IP rate limit, kill switch LEGACY_API_ENABLED.
// Static paths are declared before the {id} routes.
Route::middleware([LegacyApiEnabled::class, 'throttle:legacy-api'])->group(function () {
    foreach (['providers' => 'en', 'arabic-providers' => 'ar'] as $path => $locale) {
        Route::get($path.'/search', [LegacyProviderController::class, 'search'])->defaults('locale', $locale)->name('legacy.'.$path.'.search');
        Route::get($path.'/distinct/names', [LegacyProviderController::class, 'distinctNames'])->defaults('locale', $locale)->name('legacy.'.$path.'.names');
        Route::get($path.'/all-latlng', [LegacyProviderController::class, 'allLatLng'])->defaults('locale', $locale)->name('legacy.'.$path.'.latlng');
        Route::get($path, [LegacyProviderController::class, 'index'])->defaults('locale', $locale)->name('legacy.'.$path.'.index');
        Route::get($path.'/{id}', [LegacyProviderController::class, 'show'])->whereNumber('id')->defaults('locale', $locale)->name('legacy.'.$path.'.show');
    }
    // The statistics were declared for the English path only.
    Route::get('providers/distribution/stats', [LegacyProviderController::class, 'distribution'])->name('legacy.providers.distribution');
    Route::get('top-providers', [LegacyProviderController::class, 'top'])->name('legacy.top');

    Route::get('legacy-media/providers/{provider}/photo', [LegacyMediaController::class, 'providerPhoto'])->whereNumber('provider')->name('legacy.media.photo');
    Route::get('legacy-media/type-icons/{type}', [LegacyMediaController::class, 'typeIcon'])->whereNumber('type')->name('legacy.media.type-icon');
    Route::get('legacy-media/featured/{featured}/logo', [LegacyMediaController::class, 'featuredLogo'])->whereNumber('featured')->name('legacy.media.featured-logo');
});
