<?php

namespace App\Http\Controllers\Api\Legacy;

use App\Http\Controllers\Controller;
use App\Models\FeaturedProvider;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Services\Media\ProviderPhotoStore;
use App\Services\Media\TypeIconStore;
use App\Services\Ui\FeaturedLogoStore;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public images referenced by the legacy API (provider photo, type icon, featured logo). Lookup is
 * strictly by numeric id; the stored path must match what the store generated (never client input),
 * the image was re-encoded at upload time, and only ACTIVE records are served.
 */
class LegacyMediaController extends Controller
{
    public function providerPhoto(Provider $provider, ProviderPhotoStore $store): Response
    {
        abort_unless($provider->is_active, 404);

        return $this->serve($store, $provider->photo_path);
    }

    public function typeIcon(ProviderType $type, TypeIconStore $store): Response
    {
        abort_unless($type->is_active, 404);

        return $this->serve($store, $type->icon_path);
    }

    public function featuredLogo(FeaturedProvider $featured, FeaturedLogoStore $store): Response
    {
        abort_unless($featured->is_active, 404);

        return $this->serve($store, $featured->logo_path);
    }

    private function serve(FeaturedLogoStore $store, ?string $path): Response
    {
        abort_unless($store->exists($path), 404);

        return Storage::disk($store->disk())->response((string) $path, null, [
            'Content-Type' => $store->mimeFor((string) $path),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=3600',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Content-Disposition' => 'inline',
        ]);
    }
}
