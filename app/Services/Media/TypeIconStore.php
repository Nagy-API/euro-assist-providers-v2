<?php

namespace App\Services\Media;

use App\Services\Ui\FeaturedLogoStore;

/** Private storage for the per-type fallback icons (V1 parity, ADR-018). */
class TypeIconStore extends FeaturedLogoStore
{
    public function directory(): string
    {
        return trim((string) config('providers.media.type_icon_directory', 'type-icons'), '/');
    }
}
