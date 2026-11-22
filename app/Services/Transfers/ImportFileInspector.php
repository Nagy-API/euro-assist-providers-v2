<?php

namespace App\Services\Transfers;

use ZipArchive;

/**
 * Pre-parse safety checks on an uploaded .xlsx. Nothing is extracted or parsed: only the archive's
 * central directory (ZipArchive::statIndex) and the small [Content_Types].xml part are read.
 */
class ImportFileInspector
{
    /** Entry names that make a workbook unacceptable (macros, external links, embedded objects). */
    private const FORBIDDEN_ENTRY_PATTERNS = [
        'macro' => '#(^|/)vbaProject\.bin$#i',
        'macro_data' => '#(^|/)vbaData\.xml$#i',
        'external_link' => '#(^|/)externalLinks?(/|[^/]*\.xml)#i',
        'embedded_object' => '#(^|/)(embeddings|activeX|oleObjects?)(/|[^/]*\.(bin|xml))#i',
        'executable' => '#\.(exe|dll|bat|cmd|com|scr|js|vbs|ps1|sh|jar)$#i',
    ];

    private const MAX_CONTENT_TYPES_BYTES = 262144;

    /**
     * @throws ImportRejected
     */
    public function inspect(string $path): void
    {
        $limits = config('providers.imports');

        $size = @filesize($path);
        if ($size === false || $size === 0) {
            throw new ImportRejected('empty_file');
        }
        if ($size > (int) $limits['max_file_bytes']) {
            throw new ImportRejected('file_too_large', ['max_mb' => round($limits['max_file_bytes'] / 1048576, 1)]);
        }

        $handle = @fopen($path, 'rb');
        $signature = $handle ? fread($handle, 4) : false;
        if ($handle) {
            fclose($handle);
        }
        if ($signature !== "PK\x03\x04") {
            throw new ImportRejected('not_xlsx');
        }

        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw new ImportRejected('corrupt_file');
        }

        try {
            $this->inspectArchive($zip, $size, $limits);
        } finally {
            $zip->close();
        }
    }

    /** @param array<string, mixed> $limits */
    private function inspectArchive(ZipArchive $zip, int $fileSize, array $limits): void
    {
        $count = $zip->numFiles;
        if ($count < 1) {
            throw new ImportRejected('corrupt_file');
        }
        if ($count > (int) $limits['max_zip_entries']) {
            throw new ImportRejected('zip_too_many_entries');
        }

        $maxRatio = max(1, (int) $limits['max_zip_ratio']);
        $total = 0;

        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new ImportRejected('corrupt_file');
            }
            $name = (string) $stat['name'];

            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, "\0") || str_contains($name, '\\')) {
                throw new ImportRejected('unsafe_entry');
            }
            foreach (self::FORBIDDEN_ENTRY_PATTERNS as $reason => $pattern) {
                if (preg_match($pattern, $name) === 1) {
                    throw new ImportRejected('forbidden_content', ['reason' => $reason]);
                }
            }

            $uncompressed = (int) $stat['size'];
            $compressed = max(1, (int) $stat['comp_size']);
            $total += $uncompressed;
            if ($total > (int) $limits['max_uncompressed_bytes']) {
                throw new ImportRejected('zip_bomb');
            }
            if ($uncompressed > 1048576 && ($uncompressed / $compressed) > $maxRatio) {
                throw new ImportRejected('zip_bomb');
            }
        }
        if ($total > 1048576 && ($total / max(1, $fileSize)) > $maxRatio) {
            throw new ImportRejected('zip_bomb');
        }

        $types = $zip->locateName('[Content_Types].xml');
        if ($types === false || $zip->locateName('xl/workbook.xml') === false) {
            throw new ImportRejected('not_xlsx');
        }
        $contentTypes = $zip->getFromIndex($types, self::MAX_CONTENT_TYPES_BYTES);
        if ($contentTypes === false || $contentTypes === '') {
            throw new ImportRejected('not_xlsx');
        }
        if (stripos($contentTypes, 'macroEnabled') !== false || stripos($contentTypes, 'vbaProject') !== false) {
            throw new ImportRejected('forbidden_content', ['reason' => 'macro']);
        }
        if (stripos($contentTypes, 'spreadsheetml') === false) {
            throw new ImportRejected('not_xlsx');
        }
    }
}
