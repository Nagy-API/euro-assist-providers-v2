<?php

namespace App\Services\Ui;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Private storage for featured-provider logos.
 *
 * - The client file name is never used: every file gets a random server-generated name.
 * - The real image type comes from getimagesize() (never the client MIME type or extension);
 *   only JPEG, PNG and WebP are accepted. SVG is rejected.
 * - Images are re-encoded with GD when available, which strips metadata and any appended payload.
 * - Files live on the private disk (config providers.featured.disk), outside the web root, and are
 *   served only through the authenticated `featured.logo` route.
 */
class FeaturedLogoStore
{
    private const TYPES = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    /** Decompression-bomb guard on the decoded pixel dimensions. */
    public const MAX_DIMENSION = 4096;

    private const NAME_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|png|webp)$/';

    public function disk(): string
    {
        return (string) config('providers.featured.disk', 'local');
    }

    public function directory(): string
    {
        return trim((string) config('providers.featured.directory', 'featured-logos'), '/');
    }

    /**
     * Check that the upload is a real, acceptable image (decoded type and dimensions, not the
     * client-supplied name or MIME type). Nothing is stored.
     *
     * @return array{0: string, 1: int, 2: string} [extension, imagetype, real path]
     *
     * @throws ValidationException
     */
    public function inspect(UploadedFile $file, string $errorKey = 'logo'): array
    {
        $path = $file->getRealPath();
        $info = $path !== false ? @getimagesize($path) : false;

        if ($info === false || ! isset(self::TYPES[$info[2]])) {
            throw ValidationException::withMessages([$errorKey => __('ui.featured.logo_invalid')]);
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            throw ValidationException::withMessages([$errorKey => __('ui.featured.logo_dimensions', ['max' => self::MAX_DIMENSION])]);
        }

        return [self::TYPES[$info[2]][0], $info[2], (string) $path];
    }

    /**
     * Validate the upload as a real image and store a sanitized copy.
     *
     * @return string the stored path relative to the disk (e.g. featured-logos/<uuid>.png)
     *
     * @throws ValidationException when the file is not an acceptable image
     */
    public function store(UploadedFile $file, string $errorKey = 'logo'): string
    {
        [$extension, $type, $path] = $this->inspect($file, $errorKey);

        $bytes = (string) file_get_contents($path);
        $bytes = $this->reencode($bytes, $type) ?? $bytes;

        $name = (string) Str::uuid().'.'.$extension;
        $relative = $this->directory().'/'.$name;
        Storage::disk($this->disk())->put($relative, $bytes, 'private');

        return $relative;
    }

    /** Remove a stored logo. Ignores anything that is not a path this store would have produced. */
    public function delete(?string $relative): void
    {
        if ($relative !== null && $this->isManagedPath($relative)) {
            Storage::disk($this->disk())->delete($relative);
        }
    }

    /** True only for "<directory>/<uuid>.<jpg|png|webp>": no traversal, no foreign directories. */
    public function isManagedPath(?string $relative): bool
    {
        if ($relative === null || $relative === '' || str_contains($relative, '..') || str_contains($relative, '\\') || str_contains($relative, "\0")) {
            return false;
        }

        return dirname($relative) === $this->directory() && preg_match(self::NAME_PATTERN, basename($relative)) === 1;
    }

    public function exists(?string $relative): bool
    {
        return $this->isManagedPath($relative) && Storage::disk($this->disk())->exists((string) $relative);
    }

    /** Content type derived from the (server-generated) extension. */
    public function mimeFor(string $relative): string
    {
        return match (strtolower(pathinfo($relative, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /** Re-encode through GD; returns null when GD cannot handle it (caller keeps the validated original). */
    private function reencode(string $bytes, int $type): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }

        ob_start();
        $ok = match ($type) {
            IMAGETYPE_PNG => (function () use ($image) {
                imagesavealpha($image, true);

                return imagepng($image, null, 6);
            })(),
            IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($image, null, 85) : false,
            default => imagejpeg($image, null, 88),
        };
        $out = (string) ob_get_clean();

        return $ok && $out !== '' ? $out : null;
    }
}
