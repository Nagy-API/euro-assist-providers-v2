<?php

namespace App\Services\Legacy;

use App\Models\FeaturedProvider;
use App\Models\LegacyIdMap;
use App\Models\LegacyMigrationBatch;
use App\Models\LegacyStagingRow;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Media\ProviderPhotoStore;
use App\Services\Media\TypeIconStore;
use App\Services\Ui\FeaturedLogoStore;
use Illuminate\Validation\ValidationException;

/**
 * Copies VERIFIED legacy images from the frozen archive into V2's private media (ADR-018).
 *
 * - Reads only `<archive>/uploads/logos/` (the V1 upload folder); a reference is resolved by its
 *   file name inside that folder only (no traversal, no other folder, no symlink, no URL fetch).
 * - Every file is validated by its decoded image type and dimensions and re-encoded by the
 *   existing image store; nothing is trusted from the file name.
 * - The raw `logo_path` reference stays unchanged on the provider and in staging; V2 shows the
 *   copied picture through `photo_path` / `featured.logo_path` / `provider_types.icon_path`.
 * - V1 showed a per-type fallback icon for every provider without its own picture; those eight
 *   icon files become the type icons. A row that merely references its own type icon gets no
 *   photo (it is shown through the type icon, as in V1).
 * - Never overwrites a picture that is already set. Missing or invalid files are reported, never
 *   replaced by an invented picture.
 */
class LegacyMediaImporter
{
    /** V1 `getLogoPathAttribute()` map: type key => fallback icon file; every other type used clinics.png. */
    public const TYPE_ICON_FILES = [
        'hospital' => 'hospitals.png',
        'clinic' => 'clinics.png',
        'laboratory' => 'laboratories.png',
        'pharmacy' => 'pharmacies.png',
        'radiology_center' => 'radiology_centers.png',
        'physical_therapy' => 'physical_therapy.png',
        'specialized_center' => 'specialized_centers.png',
        'optics' => 'optics.png',
    ];

    public const DEFAULT_ICON_FILE = 'clinics.png';

    public const MAX_BYTES = 2048 * 1024;

    /** @var array<string, array{path: string, sha256: string, bytes: int}> */
    private array $files = [];

    /** @var array<string, string> source sha256 => stored type-icon path (icons are shared, never deleted by the UI) */
    private array $stored = [];

    public function __construct(
        private readonly ProviderPhotoStore $photos,
        private readonly TypeIconStore $icons,
        private readonly FeaturedLogoStore $featured,
        private readonly RowNormalizer $normalizer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{files: int, type_icons: list<array<string, mixed>>, references: list<array<string, mixed>>, counts: array<string, int>, dry_run: bool}
     */
    public function run(LegacyMigrationBatch $batch, string $archive, ?User $actor, bool $dryRun): array
    {
        LegacyGuard::assertNotProduction();
        if ($batch->status !== 'applied') {
            throw new LegacyException("Batch status is '{$batch->status}'; media is imported only for an applied batch.");
        }
        if (! $dryRun && (! $actor instanceof User || ! $actor->isAdmin())) {
            throw new LegacyException('Importing legacy media requires --actor=<e-mail of an active Admin account>.');
        }
        $this->index($archive);

        $icons = $this->typeIcons($dryRun);
        $refs = $this->references($batch, $dryRun);

        $counts = [];
        foreach ([...$icons, ...$refs] as $r) {
            $counts[$r['outcome']] = ($counts[$r['outcome']] ?? 0) + 1;
        }
        ksort($counts);

        if (! $dryRun) {
            $this->audit->record('migration.media.imported', $batch, 'success', ['batch_uuid' => $batch->uuid, 'files_indexed' => count($this->files)] + $counts, $actor);
        }

        return ['files' => count($this->files), 'type_icons' => $icons, 'references' => $refs, 'counts' => $counts, 'dry_run' => $dryRun];
    }

    private function index(string $archive): void
    {
        $root = realpath($archive);
        $dir = $root === false ? false : realpath($root.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'logos');
        if ($dir === false || ! is_dir($dir) || is_link($root.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'logos')) {
            throw new LegacyException('The archive has no uploads/logos folder.');
        }
        foreach (scandir($dir) ?: [] as $name) {
            $path = $dir.DIRECTORY_SEPARATOR.$name;
            if ($name === '.' || $name === '..' || is_link($path) || ! is_file($path)) {
                continue;
            }
            $real = realpath($path);
            if ($real === false || dirname($real) !== $dir) {
                continue;
            }
            $this->files[$name] = ['path' => $real, 'sha256' => (string) hash_file('sha256', $real), 'bytes' => (int) filesize($real)];
        }
    }

    /** @return list<array<string, mixed>> */
    private function typeIcons(bool $dryRun): array
    {
        $out = [];
        foreach (ProviderType::query()->orderBy('id')->get() as $type) {
            $file = self::TYPE_ICON_FILES[$type->key] ?? self::DEFAULT_ICON_FILE;
            $entry = ['kind' => 'type_icon', 'type_key' => $type->key, 'file' => $file];
            if ($type->icon_path !== null) {
                $out[] = $entry + ['outcome' => 'already_set'];

                continue;
            }
            $out[] = $entry + $this->copy($file, $this->icons, $dryRun, fn (string $stored) => $type->forceFill(['icon_path' => $stored])->save());
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function references(LegacyMigrationBatch $batch, bool $dryRun): array
    {
        $fallbacks = array_values(array_unique([...array_values(self::TYPE_ICON_FILES), self::DEFAULT_ICON_FILE]));
        $out = [];
        $rows = LegacyStagingRow::query()->where('migration_batch_id', $batch->id)
            ->whereIn('source_table', ['providers', 'arabic_providers', 'top_providers'])->orderBy('id')->get();

        foreach ($rows as $row) {
            $raw = $this->normalizer->clean($row->payload['logo_path'] ?? null);
            if ($raw === null) {
                continue;
            }
            $entry = ['source_table' => $row->source_table, 'source_id' => (int) $row->source_id];
            $name = $this->fileName($raw, $row->source_table === 'top_providers');
            if ($name === null) {
                $out[] = $entry + ['outcome' => preg_match('#^https?://#i', $raw) === 1 ? 'external_url_not_fetched' : 'unsafe_reference'];

                continue;
            }

            $map = LegacyIdMap::query()->where('migration_batch_id', $batch->id)
                ->where('source_table', $row->source_table)->where('source_id', $row->source_id)->first();
            if ($row->source_table === 'top_providers') {
                $featured = $map?->featured_provider_id === null ? null : FeaturedProvider::query()->find($map->featured_provider_id);
                if ($featured === null) {
                    $out[] = $entry + ['outcome' => 'not_imported_row'];

                    continue;
                }
                if ($featured->logo_path !== null) {
                    $out[] = $entry + ['outcome' => 'already_set'];

                    continue;
                }
                $out[] = $entry + $this->copy($name, $this->featured, $dryRun, fn (string $s) => $featured->forceFill(['logo_path' => $s])->save());

                continue;
            }

            if ($row->source_table === 'arabic_providers' && $map?->status === LegacyIdMap::STATUS_APPROVED) {
                // The canonical provider belongs to the English row of the pair; its picture comes from
                // that row. The Arabic reference stays in staging (and was identical for manifest pairs).
                $out[] = $entry + ['outcome' => 'paired_row_uses_canonical_picture'];

                continue;
            }
            $provider = $map?->provider_id === null ? null : Provider::query()->withTrashed()->with('type')->find($map->provider_id);
            if ($provider === null) {
                $out[] = $entry + ['outcome' => 'not_imported_row'];

                continue;
            }
            $ownIcon = self::TYPE_ICON_FILES[$provider->type->key ?? ''] ?? self::DEFAULT_ICON_FILE;
            if ($name === $ownIcon) {
                $out[] = $entry + ['outcome' => 'type_icon_reference', 'sha256' => $this->files[$name]['sha256'] ?? null];

                continue;
            }
            if (in_array($name, $fallbacks, true)) {
                $entry['note'] = 'fallback_icon_of_another_type';
            }
            if ($provider->photo_path !== null) {
                $out[] = $entry + ['outcome' => 'already_set'];

                continue;
            }
            $out[] = $entry + $this->copy($name, $this->photos, $dryRun, fn (string $s) => $provider->forceFill(['photo_path' => $s])->saveQuietly());
        }

        return $out;
    }

    /**
     * File name of a legacy reference, or null when it is not a plain name inside uploads/logos.
     * Provider rows store "uploads/logos/<name>"; featured rows store the bare "<name>".
     */
    private function fileName(string $raw, bool $bare): ?string
    {
        $ref = ltrim($raw, '/');
        if (str_contains($ref, '..') || str_contains($ref, '\\') || str_contains($ref, "\0") || preg_match('#^[a-z]+:#i', $ref) === 1) {
            return null;
        }
        if (str_starts_with($ref, 'uploads/logos/')) {
            $ref = substr($ref, strlen('uploads/logos/'));
        } elseif (! $bare) {
            return null;
        }

        return $ref === '' || str_contains($ref, '/') ? null : $ref;
    }

    /**
     * @param  callable(string): mixed  $assign
     * @return array<string, mixed>
     */
    private function copy(string $name, FeaturedLogoStore $store, bool $dryRun, callable $assign): array
    {
        $file = $this->files[$name] ?? $this->files[trim($name)] ?? null;
        if ($file === null) {
            return ['outcome' => 'missing_file'];
        }
        if ($file['bytes'] > self::MAX_BYTES) {
            return ['outcome' => 'file_too_large', 'sha256' => $file['sha256']];
        }
        $info = @getimagesize($file['path']);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return ['outcome' => 'not_an_accepted_image', 'sha256' => $file['sha256']];
        }
        if ($dryRun) {
            return ['outcome' => 'would_copy', 'sha256' => $file['sha256']];
        }

        $key = $store->directory().':'.$file['sha256'];
        try {
            // Type icons may share one stored file; a photo or logo always gets its own copy so that
            // removing one record's picture can never remove another record's picture.
            $stored = $store instanceof TypeIconStore
                ? ($this->stored[$key] ??= $store->storeFile($file['path']))
                : $store->storeFile($file['path']);
        } catch (ValidationException) {
            return ['outcome' => 'not_an_accepted_image', 'sha256' => $file['sha256']];
        }
        $assign($stored);

        return ['outcome' => 'copied', 'sha256' => $file['sha256'], 'stored' => $stored];
    }
}
