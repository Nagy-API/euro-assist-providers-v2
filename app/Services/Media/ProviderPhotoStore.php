<?php

namespace App\Services\Media;

use App\Services\Ui\FeaturedLogoStore;

/**
 * Private storage for provider photos. Same rules as featured logos (decoded image type only,
 * JPEG/PNG/WebP, re-encoded, random server-side name, private disk, authorized route only).
 */
class ProviderPhotoStore extends FeaturedLogoStore
{
    public function directory(): string
    {
        return trim((string) config('providers.media.photo_directory', 'provider-photos'), '/');
    }
}
