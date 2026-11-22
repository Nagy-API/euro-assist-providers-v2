<?php

namespace App\Services\Transfers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Helpers for private-disk files with SERVER-GENERATED names (the client file name never reaches
 * a path). OpenSpout needs real filesystem paths, so the configured disk must be a local one.
 */
final class PrivateFiles
{
    /** A new random relative path like "imports/2026/11/<uuid>.xlsx". */
    public static function newPath(string $directory, string $extension = 'xlsx'): string
    {
        return trim($directory, '/').'/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
    }

    /** Absolute path for a relative path on a disk, creating the parent directory. */
    public static function absolute(string $disk, string $relative, bool $ensureDirectory = false): string
    {
        $absolute = Storage::disk($disk)->path($relative);
        if ($ensureDirectory) {
            $dir = dirname($absolute);
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }

        return $absolute;
    }

    public static function delete(string $disk, ?string $relative): void
    {
        if ($relative !== null && $relative !== '') {
            Storage::disk($disk)->delete($relative);
        }
    }

    /** Display-safe version of a client file name (never used in a path). */
    public static function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]+/u', '', $name) ?? '';
        $name = trim($name);
        if ($name === '') {
            $name = 'upload.xlsx';
        }

        return mb_substr($name, 0, 150);
    }
}
