<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Services\Media\ProviderPhotoStore;
use App\Services\Media\TypeIconStore;
use App\Services\Ui\FeaturedLogoStore;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves provider photos and type icons from the private disk to authenticated users with
 * providers.view only. A stored path must match what the store generates; anything else is a 404.
 */
class ProviderMediaController extends Controller
{
    public function photo(Provider $provider, ProviderPhotoStore $store): Response
    {
        Gate::authorize('view', $provider);

        return $this->serve($store, $provider->photo_path);
    }

    public function typeIcon(ProviderType $providerType, TypeIconStore $store): Response
    {
        Gate::authorize('viewAny', Provider::class);

        return $this->serve($store, $providerType->icon_path);
    }

    private function serve(FeaturedLogoStore $store, ?string $path): Response
    {
        abort_unless($store->exists($path), 404);

        return Storage::disk($store->disk())->response((string) $path, null, [
            'Content-Type' => $store->mimeFor((string) $path),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Disposition' => 'inline',
        ]);
    }
}
