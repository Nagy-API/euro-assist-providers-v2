<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeaturedProvider;
use App\Services\Ui\FeaturedLogoStore;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves featured-provider logos from the private disk to authenticated users only. The stored
 * path must match what FeaturedLogoStore generates; anything else (traversal, foreign directory)
 * is treated as "no logo".
 */
class FeaturedLogoController extends Controller
{
    public function __invoke(FeaturedProvider $featured, FeaturedLogoStore $store): Response
    {
        Gate::authorize('viewAny', FeaturedProvider::class);

        $path = $featured->logo_path;
        abort_unless($store->exists($path), 404);

        $disk = Storage::disk($store->disk());

        return $disk->response((string) $path, null, [
            'Content-Type' => $store->mimeFor((string) $path),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Disposition' => 'inline',
        ]);
    }
}
