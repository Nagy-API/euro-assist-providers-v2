<?php

namespace App\Services\Legacy;

use App\Models\LegacyTypeMapping;
use App\Models\ProviderType;
use Database\Seeders\ProviderTypeSeeder;

/**
 * Builds the versioned raw-label -> provider_types.key mapping (decision D3, provisional).
 *
 * APPROVED only where the evidence is unambiguous: an exact English label, Arabic label or key
 * of the 30-type vocabulary (trailing-space variants collapse because raw values are trimmed
 * before lookup). Plural display labels are recommendations that still need the owner's D3
 * approval, so they are stored as needs_review with a note and rows using them are held back.
 */
class TypeMappingService
{
    /**
     * Display labels recorded in docs/05_DATA_MIGRATION.md (P1.4 proposal, section 2) that differ
     * from the vocabulary labels. Recommendation only: seeded as needs_review.
     *
     * @var array<string, string> raw label => type key
     */
    public const REVIEW_RECOMMENDATIONS = [
        'Laboratories' => 'laboratory',
        'Radiology Centers' => 'radiology_center',
        'Specialized Centers' => 'specialized_center',
        'Physiotherapy Center' => 'physical_therapy',
    ];

    /** Legacy (pre-ADR-014) note of the first two recommendations; upgraded to PROVISIONAL. */
    public const REVIEW_NOTE = 'Plural display label; recommended mapping awaits owner approval of decision D3.';

    public const PROVISIONAL_NOTE = 'PROVISIONAL: deterministic plural/synonym of a vocabulary label. Used only by legacy:apply --accept-provisional-types (local rehearsal); owner approval of D3 pending.';

    /**
     * Labels that are genuinely ambiguous: recorded as needs_review WITHOUT a type so they can never
     * be resolved by a flag. Only an owner decision can map them.
     *
     * @var array<string, string> raw label => note
     */
    public const AMBIGUOUS = [
        'Eye Center' => 'Ambiguous: could be Optics, Specialized Center or Clinic. Owner decision required (D3).',
    ];

    /**
     * Seed the static vocabulary into mapping version $version. Idempotent.
     *
     * @return array{approved: int, needs_review: int, skipped: int}
     */
    public function seedVocabulary(string $version): array
    {
        $types = ProviderType::query()->pluck('id', 'key');
        $stats = ['approved' => 0, 'needs_review' => 0, 'skipped' => 0];

        foreach (ProviderTypeSeeder::TYPES as $key => [$en, $ar]) {
            $typeId = $types[$key] ?? null;
            if ($typeId === null) {
                $stats['skipped']++;

                continue;
            }
            foreach ([$key, $en, $ar] as $raw) {
                $this->put($version, $raw, $typeId, TypeMapper::APPROVED, null) ? $stats['approved']++ : $stats['skipped']++;
            }
        }

        foreach (self::REVIEW_RECOMMENDATIONS as $raw => $key) {
            $typeId = $types[$key] ?? null;
            // Upgrade our own earlier needs_review seed (never a reviewer's decision) to PROVISIONAL.
            LegacyTypeMapping::query()->where('mapping_version', $version)->where('raw_value', $raw)
                ->where('status', TypeMapper::NEEDS_REVIEW)->where('note', self::REVIEW_NOTE)
                ->update(['status' => TypeMapper::PROVISIONAL, 'note' => self::PROVISIONAL_NOTE, 'provider_type_id' => $typeId]);
            $this->put($version, $raw, $typeId, TypeMapper::PROVISIONAL, self::PROVISIONAL_NOTE) ? $stats['needs_review']++ : $stats['skipped']++;
        }
        foreach (self::AMBIGUOUS as $raw => $note) {
            $this->put($version, $raw, null, TypeMapper::NEEDS_REVIEW, $note) ? $stats['needs_review']++ : $stats['skipped']++;
        }

        return $stats;
    }

    /**
     * Align the mapping with the frozen `types` table: a row's type_key / name_en / name_ar map to
     * the provider type with the same key (approved); a type_key V2 does not know is recorded as
     * needs_review. Reads the legacy table only.
     *
     * @param  list<array<string, mixed>>  $legacyTypes
     * @return array{approved: int, needs_review: int, skipped: int}
     */
    public function syncFromLegacy(string $version, array $legacyTypes, bool $dryRun = false): array
    {
        $normalizer = new RowNormalizer;
        $types = ProviderType::query()->pluck('id', 'key');
        $stats = ['approved' => 0, 'needs_review' => 0, 'skipped' => 0];

        foreach ($legacyTypes as $row) {
            $key = $normalizer->clean($row['type_key'] ?? null);
            if ($key === null) {
                $stats['skipped']++;

                continue;
            }
            $typeId = $types[$key] ?? null;
            foreach ([$key, $normalizer->clean($row['name_en'] ?? null), $normalizer->clean($row['name_ar'] ?? null)] as $raw) {
                if ($raw === null) {
                    continue;
                }
                $status = $typeId === null ? TypeMapper::NEEDS_REVIEW : TypeMapper::APPROVED;
                $note = $typeId === null ? 'Legacy type_key has no provider type in V2.' : null;
                if ($dryRun) {
                    $stats[$status]++;

                    continue;
                }
                $this->put($version, $raw, $typeId, $status, $note) ? $stats[$status]++ : $stats['skipped']++;
            }
        }

        return $stats;
    }

    /**
     * Insert a mapping unless a (case/trailing-space equivalent) one already exists for the
     * version. Existing rows are never overwritten (a reviewer may have approved them).
     */
    private function put(string $version, string $raw, ?int $typeId, string $status, ?string $note): bool
    {
        $raw = trim($raw);
        $exists = LegacyTypeMapping::query()->where('mapping_version', $version)->where('raw_value', $raw)->exists();
        if ($exists) {
            return false;
        }

        LegacyTypeMapping::query()->create([
            'mapping_version' => $version,
            'raw_value' => $raw,
            'provider_type_id' => $typeId,
            'status' => $status,
            'note' => $note,
        ]);

        return true;
    }
}
