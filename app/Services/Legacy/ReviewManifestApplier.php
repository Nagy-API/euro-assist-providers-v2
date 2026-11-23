<?php

namespace App\Services\Legacy;

use App\Enums\Permission;
use App\Models\LegacyIdMap;
use App\Models\LegacyMigrationBatch;
use App\Models\LegacyPairSuggestion;
use App\Models\LegacyRowDecision;
use App\Models\LegacyStagingRow;
use App\Models\LegacyTypeMapping;
use App\Models\Provider;
use App\Models\ProviderTranslation;
use App\Models\ProviderType;
use App\Models\User;
use App\Rules\SafeHttpUrl;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Applies an owner-reviewed migration decision manifest (ADR-017) WITHOUT trusting it.
 *
 * The manifest is a set of proposals made outside V2 from the frozen SQL. Every proposal is
 * re-verified here against the actual V2 destination before anything is written, and every write
 * goes through the existing audited services (PairReviewService for pairs, LegacyApplier for the
 * rows a decision releases). A proposal that fails any check is left exactly as it is and is
 * reported with machine-readable reason codes (never provider values).
 *
 * Pair checks are deliberately stricter than the suggester: the map link and the coordinates parsed
 * from it count as ONE piece of evidence, a shared phone number is never treated as branch-specific,
 * and the branch must be corroborated independently (city correspondence across the reviewed pairs,
 * compatible address numbers, no other record on the same pin, no conflicting canonical value).
 */
class ReviewManifestApplier
{
    public const SIGNAL_MANIFEST = 'owner_review_manifest';

    public const SCHEMA_VERSION = '1.0';

    public const PAIR_STATUS = 'STRONG_EVIDENCE_PROPOSED_MERGE';

    public const ALIAS_DECISION = 'APPROVE_EXACT_SEMANTIC_ALIAS';

    public const EYE_PROPOSAL = 'SOURCE_AR_OPTICS_PROPOSAL';

    public const MAP_REPAIR = 'SAFE_FIELD_REPAIR_TARGET_ONLY';

    /** Canonical provider columns that must be identical on both sides of an applied pair. */
    private const CANONICAL_FIELDS = ['provider_type_id', 'phone', 'email', 'website', 'map_url', 'latitude', 'longitude', 'logo_path', 'photo_path', 'is_active'];

    /** Provider columns that must still equal the normalized source value (no edit since migration). */
    private const SOURCE_FIELDS = ['phone', 'email', 'website', 'map_url', 'logo_path'];

    private string $manifestSha = '';

    /** Resolves Arabic raw type labels with APPROVED mappings only (independent corroboration). */
    private ?TypeMapper $arabicMapper = null;

    public function __construct(
        private readonly PairReviewService $pairs,
        private readonly LegacyApplier $applier,
        private readonly RowNormalizer $normalizer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{
     *   manifest_sha256: string, dry_run: bool,
     *   aliases: list<array<string, mixed>>, row_decisions: list<array<string, mixed>>,
     *   pairs: list<array<string, mixed>>, superseded_suggestions: int,
     *   counts: array<string, int>, hold_reasons: array<string, int>
     * }
     */
    public function run(LegacyMigrationBatch $batch, string $manifestPath, ?User $actor, bool $dryRun, ?string $expectedSha256 = null): array
    {
        LegacyGuard::assertNotProduction();
        if ($batch->status !== 'applied') {
            throw new LegacyException("Batch status is '{$batch->status}'; a review manifest applies only to an applied batch.");
        }
        $manifest = $this->load($manifestPath, $batch);
        if ($expectedSha256 !== null && $expectedSha256 !== '' && ! hash_equals(strtolower($expectedSha256), $this->manifestSha)) {
            throw new LegacyException('The manifest SHA-256 does not match --expect-sha256; refusing.');
        }

        if (! $dryRun) {
            if (! $actor instanceof User || ! $actor->isAdmin() || ! Gate::forUser($actor)->allows(Permission::MigrationReview->value)) {
                throw new LegacyException('Applying a review manifest requires --actor=<e-mail of an active Admin account>.');
            }
        }

        $aliases = $this->typeAliases($batch, $manifest, $actor, $dryRun);
        $rowDecisions = $this->rowDecisions($batch, $manifest, $actor, $dryRun);

        // Release the rows a decision unblocked (eye rows, repaired map links) through the normal
        // applier: same validation, same audit, same id-map bookkeeping as every other row.
        $pendingDecided = LegacyRowDecision::query()->where('legacy_row_decisions.migration_batch_id', $batch->id)
            ->join('legacy_staging_rows as s', function ($j) {
                $j->on('s.migration_batch_id', '=', 'legacy_row_decisions.migration_batch_id')
                    ->on('s.source_table', '=', 'legacy_row_decisions.source_table')
                    ->on('s.source_id', '=', 'legacy_row_decisions.source_id');
            })->where('s.state', '<>', LegacyStagingRow::STATE_MAPPED)->exists();
        if (! $dryRun && $pendingDecided) {
            $this->applier->apply($batch, $actor, false);
        }

        [$pairs, $superseded] = $this->strictPairs($batch, $manifest, $actor, $dryRun);

        $counts = [
            'alias_approved' => 0, 'alias_held' => 0,
            'row_decision_recorded' => 0, 'row_decision_held' => 0,
            'pairs_applied' => 0, 'pairs_already_applied' => 0, 'pairs_held' => 0,
        ];
        $holdReasons = [];
        foreach ($aliases as $a) {
            $counts[in_array($a['outcome'], ['approved', 'would_approve', 'already_approved'], true) ? 'alias_approved' : 'alias_held']++;
        }
        foreach ($rowDecisions as $d) {
            $counts[in_array($d['outcome'], ['recorded', 'would_record', 'already_recorded'], true) ? 'row_decision_recorded' : 'row_decision_held']++;
        }
        foreach ($pairs as $p) {
            if (in_array($p['outcome'], ['applied', 'would_apply'], true)) {
                $counts['pairs_applied']++;
            } elseif ($p['outcome'] === 'already_applied') {
                $counts['pairs_already_applied']++;
            } else {
                $counts['pairs_held']++;
                foreach ($p['codes'] as $code) {
                    $key = explode(':', $code, 2)[0];
                    $holdReasons[$key] = ($holdReasons[$key] ?? 0) + 1;
                }
            }
        }
        ksort($holdReasons);

        if (! $dryRun) {
            $this->audit->record('migration.manifest.applied', $batch, 'success', [
                'manifest_sha256' => $this->manifestSha, 'batch_uuid' => $batch->uuid,
            ] + $counts, $actor);
        }

        return [
            'manifest_sha256' => $this->manifestSha,
            'dry_run' => $dryRun,
            'aliases' => $aliases,
            'row_decisions' => $rowDecisions,
            'pairs' => $pairs,
            'superseded_suggestions' => $superseded,
            'counts' => $counts,
            'hold_reasons' => $holdReasons,
        ];
    }

    /**
     * Canonical row fingerprint, identical to the reviewer's Python tool: every value as the text
     * the SQL dump contains (NULL stays null), keys sorted, compact UTF-8 JSON.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function rowFingerprint(array $payload): string
    {
        $text = [];
        foreach ($payload as $key => $value) {
            $text[(string) $key] = match (true) {
                $value === null => null,
                is_bool($value) => $value ? '1' : '0',
                is_scalar($value) => (string) $value,
                default => json_encode($value),
            };
        }
        ksort($text, SORT_STRING);

        return hash('sha256', json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function load(string $path, LegacyMigrationBatch $batch): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new LegacyException('The review manifest file cannot be read.');
        }
        $raw = (string) file_get_contents($path);
        $this->manifestSha = hash('sha256', $raw);

        $manifest = json_decode($raw, true);
        if (! is_array($manifest) || ($manifest['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new LegacyException('The review manifest is not a schema '.self::SCHEMA_VERSION.' manifest.');
        }
        $source = strtolower((string) ($manifest['source_sha256'] ?? ''));
        if ($source === '' || ! hash_equals(strtolower($batch->snapshot_sha256), $source)
            || ! hash_equals(strtolower((string) config('legacy.snapshot_sha256')), $source)) {
            throw new LegacyException('The review manifest was made from a different snapshot; refusing.');
        }
        $required = [
            'strict_pairs' => ['en_source_id' => 'int', 'ar_source_id' => 'int', 'en_row_sha256' => 'string', 'ar_row_sha256' => 'string', 'shared_map_sha256' => 'string', 'type_key' => 'string', 'status' => 'string'],
            'type_alias_decisions' => ['source_label' => 'string', 'target_type_key' => 'string', 'source_count' => 'int', 'decision' => 'string', 'basis' => '?string'],
            'eye_center_decisions' => ['en_source_id' => 'int', 'status' => 'string', 'en_row_sha256' => '?string', 'matching_ar_source_id' => '?int', 'ar_type_raw' => '?string', 'proposed_type_key' => '?string'],
            'repair_map_urls' => ['source_table' => 'string', 'source_id' => 'int', 'source_row_sha256' => 'string', 'original_map_url_sha256' => 'string', 'repaired_map_url' => 'string', 'status' => 'string'],
        ];
        // The whole structure is validated BEFORE anything is written, so a malformed entry can never
        // stop a run half-way.
        foreach ($required as $key => $fields) {
            if (! isset($manifest[$key]) || ! is_array($manifest[$key]) || ! array_is_list($manifest[$key])) {
                throw new LegacyException("The review manifest has no '{$key}' list.");
            }
            foreach ($manifest[$key] as $n => $entry) {
                if (! is_array($entry)) {
                    throw new LegacyException("Review manifest entry {$key}[{$n}] is not an object.");
                }
                foreach ($fields as $field => $type) {
                    $value = $entry[$field] ?? null;
                    if (str_starts_with($type, '?')) {
                        if ($value === null) {
                            continue;
                        }
                        $type = substr($type, 1);
                    }
                    if (($type === 'int' && ! is_int($value)) || ($type === 'string' && ! is_string($value))) {
                        throw new LegacyException("Review manifest entry {$key}[{$n}] has no valid '{$field}'.");
                    }
                }
            }
        }

        return $manifest;
    }

    // ---------------------------------------------------------------- type aliases (D3)

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array<string, mixed>>
     */
    private function typeAliases(LegacyMigrationBatch $batch, array $manifest, ?User $actor, bool $dryRun): array
    {
        $out = [];
        /** @var array<int, int> $arFor English source id => Arabic source id of a reviewed pair */
        $arFor = [];
        foreach ((array) $manifest['strict_pairs'] as $pair) {
            if (is_array($pair)) {
                $arFor[(int) ($pair['en_source_id'] ?? 0)] = (int) ($pair['ar_source_id'] ?? 0);
            }
        }

        foreach ($manifest['type_alias_decisions'] as $d) {
            $label = TypeMapper::key((string) ($d['source_label'] ?? ''));
            $entry = ['source_label' => $label, 'target_type_key' => $d['target_type_key'] ?? null, 'codes' => []];

            if (($d['decision'] ?? null) !== self::ALIAS_DECISION) {
                $out[] = $entry + ['outcome' => 'not_proposed'];

                continue;
            }
            $type = ProviderType::query()->where('key', (string) $d['target_type_key'])->first();
            $mapping = LegacyTypeMapping::query()->where('mapping_version', $batch->mapping_version)->get()
                ->first(fn (LegacyTypeMapping $m) => TypeMapper::key($m->raw_value) === $label);

            $rows = $this->stagedRows($batch, 'providers')->filter(fn (LegacyStagingRow $r) => TypeMapper::key((string) $this->normalizer->clean($r->payload['type'] ?? null)) === $label);

            $codes = [];
            if ($type === null) {
                $codes[] = 'unknown_target_type';
            }
            if ($mapping === null) {
                $codes[] = 'mapping_missing';
            } elseif ($type !== null && (int) $mapping->provider_type_id !== (int) $type->id) {
                $codes[] = 'mapping_target_differs';
            } elseif (! in_array($mapping->status, [TypeMapper::PROVISIONAL, TypeMapper::APPROVED], true)) {
                $codes[] = 'mapping_not_provisional';
            }
            if ($rows->count() !== (int) ($d['source_count'] ?? -1)) {
                $codes[] = 'source_count_changed';
            }

            // Independent corroboration: the Arabic counterpart of a reviewed pair classifies the same
            // branch with the target type in its own vocabulary.
            $corroborated = 0;
            $contradicted = 0;
            if ($type !== null) {
                foreach ($rows as $row) {
                    if (! isset($arFor[(int) $row->source_id])) {
                        continue;
                    }
                    $arType = $this->arabicTypeId($batch, $arFor[(int) $row->source_id]);
                    $arType === (int) $type->id ? $corroborated++ : $contradicted++;
                }
                if ($corroborated === 0) {
                    $codes[] = 'no_arabic_corroboration';
                }
                if ($contradicted > 0) {
                    $codes[] = 'arabic_contradiction';
                }
            }
            $entry = ['codes' => $codes, 'corroborated_pairs' => $corroborated, 'rows' => $rows->count()] + $entry;

            if ($codes !== []) {
                $out[] = ['outcome' => 'held'] + $entry;

                continue;
            }
            /** @var LegacyTypeMapping $mapping */
            if ($mapping->status === TypeMapper::APPROVED) {
                $out[] = ['outcome' => 'already_approved'] + $entry;

                continue;
            }
            if ($dryRun) {
                $out[] = ['outcome' => 'would_approve'] + $entry;

                continue;
            }

            $mapping->forceFill([
                'status' => TypeMapper::APPROVED,
                'note' => mb_substr('Owner D3 decision (review manifest '.substr($this->manifestSha, 0, 12).', 2026-11-23): '.(string) ($d['basis'] ?? 'exact semantic alias').' Raw label preserved in staging.', 0, 500),
            ])->save();
            $released = LegacyStagingRow::query()->whereIn('id', $rows->pluck('id'))
                ->where('state', LegacyStagingRow::STATE_MAPPED)->where('reason', 'provisional_type')
                ->update(['reason' => 'type_alias_approved']);

            $this->audit->record('migration.type_mapping.approved', ['legacy_type_mapping', $mapping->id], 'success', [
                'manifest_sha256' => $this->manifestSha, 'target_type_key' => $type->key, 'rows' => $rows->count(), 'released_rows' => $released,
            ], $actor);
            $out[] = ['outcome' => 'approved', 'released_rows' => $released] + $entry;
        }

        return $out;
    }

    // ---------------------------------------------------------------- row decisions

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array<string, mixed>>
     */
    private function rowDecisions(LegacyMigrationBatch $batch, array $manifest, ?User $actor, bool $dryRun): array
    {
        $out = [];

        foreach ($manifest['eye_center_decisions'] as $d) {
            $id = (int) ($d['en_source_id'] ?? 0);
            $entry = ['kind' => 'eye_center_type', 'source_table' => 'providers', 'source_id' => $id, 'manifest_status' => $d['status'] ?? null];
            if (($d['status'] ?? null) !== self::EYE_PROPOSAL || empty($d['proposed_type_key'])) {
                $out[] = $entry + ['outcome' => 'held_by_manifest', 'codes' => ['manifest_hold']];

                continue;
            }

            $codes = [];
            $row = $this->stagedRow($batch, 'providers', $id);
            $type = ProviderType::query()->where('key', (string) $d['proposed_type_key'])->first();
            if ($row === null) {
                $codes[] = 'staging_missing';
            } else {
                if (self::rowFingerprint($row->payload) !== ($d['en_row_sha256'] ?? '')) {
                    $codes[] = 'source_row_changed';
                }
                if (TypeMapper::key((string) $this->normalizer->clean($row->payload['type'] ?? null)) !== 'eye center') {
                    $codes[] = 'not_an_eye_center_row';
                }
            }
            if ($type === null) {
                $codes[] = 'unknown_target_type';
            }
            // Corroboration: the matching Arabic source row is classified as the proposed type in V2.
            $arRow = $this->stagedRow($batch, 'arabic_providers', (int) ($d['matching_ar_source_id'] ?? 0));
            if ($arRow === null || $type === null || $this->arabicTypeId($batch, (int) $arRow->source_id) !== (int) $type->id) {
                $codes[] = 'arabic_type_not_corroborated';
            } elseif ($this->normalizer->clean($arRow->payload['type'] ?? null) !== ($d['ar_type_raw'] ?? null)) {
                $codes[] = 'arabic_raw_type_changed';
            }

            $out[] = $entry + $this->recordDecision($batch, $row, LegacyRowDecision::FIELD_TYPE_KEY, (string) ($type->key ?? ''),
                $row === null ? null : (string) $this->normalizer->clean($row->payload['type'] ?? null),
                'Owner decision (review manifest '.substr($this->manifestSha, 0, 12).'): this Eye Center row is the optics branch its Arabic source row classifies as optics; raw type preserved.',
                $codes, $actor, $dryRun);
        }

        foreach ($manifest['repair_map_urls'] as $d) {
            $table = (string) ($d['source_table'] ?? '');
            $id = (int) ($d['source_id'] ?? 0);
            $entry = ['kind' => 'map_url_repair', 'source_table' => $table, 'source_id' => $id, 'manifest_status' => $d['status'] ?? null];
            if (($d['status'] ?? null) !== self::MAP_REPAIR || ! in_array($table, ['providers', 'arabic_providers'], true)) {
                $out[] = $entry + ['outcome' => 'held_by_manifest', 'codes' => ['manifest_hold']];

                continue;
            }

            $codes = [];
            $row = $this->stagedRow($batch, $table, $id);
            $repaired = (string) ($d['repaired_map_url'] ?? '');
            $rawMap = null;
            if ($row === null) {
                $codes[] = 'staging_missing';
            } else {
                $rawMap = is_string($row->payload['map_url'] ?? null) ? $row->payload['map_url'] : null;
                if (self::rowFingerprint($row->payload) !== ($d['source_row_sha256'] ?? '')) {
                    $codes[] = 'source_row_changed';
                }
                if ($rawMap === null || hash('sha256', $rawMap) !== ($d['original_map_url_sha256'] ?? '')) {
                    $codes[] = 'raw_map_fingerprint_mismatch';
                } else {
                    $parts = explode(' ', $rawMap);
                    if (count($parts) !== 2 || $parts[1] !== $repaired) {
                        $codes[] = 'unexpected_split';
                    }
                }
            }
            $valid = Validator::make(['u' => $repaired], ['u' => ['required', 'string', 'max:2048', new SafeHttpUrl(allowBare: false)]]);
            if (! str_starts_with($repaired, 'https://') || $valid->fails()) {
                $codes[] = 'repaired_url_invalid';
            }

            $out[] = $entry + $this->recordDecision($batch, $row, LegacyRowDecision::FIELD_MAP_URL, $repaired, $rawMap,
                'Owner decision (review manifest '.substr($this->manifestSha, 0, 12).'): the raw link holds two URLs joined by a space; the second complete URL is used. Raw link preserved in staging.',
                $codes, $actor, $dryRun);
        }

        return $out;
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, mixed>
     */
    private function recordDecision(LegacyMigrationBatch $batch, ?LegacyStagingRow $row, string $field, string $value, ?string $original, string $reason, array $codes, ?User $actor, bool $dryRun): array
    {
        if ($row !== null) {
            $existing = LegacyRowDecision::query()->where('migration_batch_id', $batch->id)
                ->where('source_table', $row->source_table)->where('source_id', $row->source_id)->where('field', $field)->first();
            if ($existing !== null) {
                return ['outcome' => $existing->value === $value && $codes === [] ? 'already_recorded' : 'held', 'codes' => $existing->value === $value ? $codes : [...$codes, 'different_decision_exists']];
            }
            if ($row->state === LegacyStagingRow::STATE_MAPPED) {
                $codes[] = 'row_already_mapped';
            }
        }
        if ($codes !== [] || $row === null) {
            return ['outcome' => 'held', 'codes' => $codes];
        }
        if ($dryRun) {
            return ['outcome' => 'would_record', 'codes' => []];
        }

        $decision = LegacyRowDecision::query()->create([
            'migration_batch_id' => $batch->id,
            'source_table' => $row->source_table,
            'source_id' => $row->source_id,
            'field' => $field,
            'value' => $value,
            'original_sha256' => $original === null ? null : hash('sha256', $original),
            'reason' => $reason,
            'manifest_sha256' => $this->manifestSha,
            'decided_by' => $actor?->getKey(),
        ]);
        $this->audit->record('migration.row_decision.recorded', ['legacy_row_decision', $decision->id], 'success', [
            'manifest_sha256' => $this->manifestSha, 'source_table' => $row->source_table, 'source_id' => $row->source_id, 'field' => $field,
        ], $actor);

        return ['outcome' => 'recorded', 'codes' => []];
    }

    // ---------------------------------------------------------------- bilingual pairs

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function strictPairs(LegacyMigrationBatch $batch, array $manifest, ?User $actor, bool $dryRun): array
    {
        $en = $this->stagedRows($batch, 'providers')->keyBy('source_id');
        $ar = $this->stagedRows($batch, 'arabic_providers')->keyBy('source_id');
        $maps = LegacyIdMap::query()->where('migration_batch_id', $batch->id)->whereIn('source_table', ['providers', 'arabic_providers'])
            ->get()->keyBy(fn (LegacyIdMap $m) => $m->source_table.':'.$m->source_id);
        $types = ProviderType::query()->pluck('id', 'key');

        // City correspondence learnt from the reviewed pairs themselves (independent of the map link).
        $cityPairs = [];
        foreach ($manifest['strict_pairs'] as $p) {
            $ec = $this->cityKey($en->get((int) $p['en_source_id'])?->payload['city'] ?? null);
            $ac = $this->cityKey($ar->get((int) $p['ar_source_id'])?->payload['city'] ?? null);
            if ($ec !== null && $ac !== null) {
                $cityPairs[$ec][$ac] = ($cityPairs[$ec][$ac] ?? 0) + 1;
            }
        }
        $reverse = $this->invert($cityPairs);

        // District correspondence, same idea: a pair whose districts contradict a correspondence
        // seen in at least two other reviewed pairs is not the same branch until a human says so.
        $districtPairs = [];
        foreach ($manifest['strict_pairs'] as $p) {
            $ed = $this->placeKey($en->get((int) $p['en_source_id'])?->payload['district'] ?? null);
            $ad = $this->placeKey($ar->get((int) $p['ar_source_id'])?->payload['district'] ?? null);
            if ($ed !== null && $ad !== null) {
                $districtPairs[$ed][$ad] = ($districtPairs[$ed][$ad] ?? 0) + 1;
            }
        }
        $districtReverse = $this->invert($districtPairs);

        // Every map link / pin held by a live provider or a held staging row (for uniqueness checks).
        $mapUse = [];
        $pinUse = [];
        foreach (Provider::query()->get(['id', 'map_url', 'latitude', 'longitude']) as $p) {
            if ($p->map_url !== null) {
                $mapUse[$p->map_url][] = 'p'.$p->id;
            }
            if ($p->latitude !== null && $p->longitude !== null) {
                $pinUse[$this->pin($p->latitude, $p->longitude)][] = 'p'.$p->id;
            }
        }
        foreach (LegacyStagingRow::query()->where('migration_batch_id', $batch->id)->where('state', '<>', LegacyStagingRow::STATE_MAPPED)->get() as $held) {
            $m = $this->normalizer->provider((array) $held->payload)['map_url'];
            if ($m !== null) {
                $mapUse[$m][] = 's'.$held->id;
            }
        }

        $out = [];
        $superseded = 0;
        $seenEn = [];
        $seenAr = [];
        foreach ($manifest['strict_pairs'] as $p) {
            $eId = (int) ($p['en_source_id'] ?? 0);
            $aId = (int) ($p['ar_source_id'] ?? 0);
            $entry = ['en_source_id' => $eId, 'ar_source_id' => $aId];
            $codes = [];

            if (($p['status'] ?? null) !== self::PAIR_STATUS || ($p['en_source_table'] ?? 'providers') !== 'providers' || ($p['ar_source_table'] ?? 'arabic_providers') !== 'arabic_providers') {
                $out[] = $entry + ['outcome' => 'held', 'codes' => ['manifest_not_proposed']];

                continue;
            }
            if (isset($seenEn[$eId]) || isset($seenAr[$aId])) {
                $out[] = $entry + ['outcome' => 'held', 'codes' => ['manifest_not_one_to_one']];

                continue;
            }
            $seenEn[$eId] = $seenAr[$aId] = true;

            $eRow = $en->get($eId);
            $aRow = $ar->get($aId);
            $eMap = $maps->get('providers:'.$eId);
            $aMap = $maps->get('arabic_providers:'.$aId);

            if ($eMap !== null && $aMap !== null && $eMap->status === LegacyIdMap::STATUS_APPROVED && $aMap->status === LegacyIdMap::STATUS_APPROVED
                && $aMap->provider_id === $eMap->provider_id && (int) (($aMap->review_evidence ?? [])['paired_with_map_id'] ?? 0) === $eMap->id) {
                $out[] = $entry + ['outcome' => 'already_applied', 'codes' => []];

                continue;
            }

            if ($eRow === null || $aRow === null) {
                $out[] = $entry + ['outcome' => 'held', 'codes' => ['staging_missing']];

                continue;
            }
            if (self::rowFingerprint($eRow->payload) !== ($p['en_row_sha256'] ?? '') || self::rowFingerprint($aRow->payload) !== ($p['ar_row_sha256'] ?? '')) {
                $codes[] = 'source_row_changed';
            }
            $eRawMap = is_string($eRow->payload['map_url'] ?? null) ? $eRow->payload['map_url'] : null;
            $aRawMap = is_string($aRow->payload['map_url'] ?? null) ? $aRow->payload['map_url'] : null;
            if ($eRawMap === null || $eRawMap !== $aRawMap || hash('sha256', $eRawMap) !== ($p['shared_map_sha256'] ?? '')) {
                $codes[] = 'map_fingerprint_mismatch';
            }
            if ($eRow->state !== LegacyStagingRow::STATE_MAPPED || $aRow->state !== LegacyStagingRow::STATE_MAPPED) {
                $codes[] = 'staging_not_mapped';
            }
            if ($eMap === null || $aMap === null || $eMap->status !== LegacyIdMap::STATUS_UNPAIRED || $aMap->status !== LegacyIdMap::STATUS_UNPAIRED
                || $eMap->provider_id === null || $aMap->provider_id === null || $eMap->provider_id === $aMap->provider_id) {
                $out[] = $entry + ['outcome' => 'held', 'codes' => [...$codes, 'not_unpaired']];

                continue;
            }

            $eP = Provider::query()->find($eMap->provider_id);
            $aP = Provider::query()->find($aMap->provider_id);
            if ($eP === null || $aP === null) {
                $out[] = $entry + ['outcome' => 'held', 'codes' => [...$codes, 'provider_missing_or_deleted']];

                continue;
            }
            if ((int) $eP->revision !== 1 || (int) $aP->revision !== 1) {
                $codes[] = 'provider_edited_since_migration';
            }
            $eN = $this->normalizer->provider((array) $eRow->payload);
            $aN = $this->normalizer->provider((array) $aRow->payload);
            foreach (self::SOURCE_FIELDS as $f) {
                if ($eP->getAttribute($f) !== $eN[$f] || $aP->getAttribute($f) !== $aN[$f]) {
                    $codes[] = 'provider_differs_from_source:'.$f;
                }
            }
            $eT = ProviderTranslation::query()->where('provider_id', $eP->id)->pluck('locale')->all();
            $aT = ProviderTranslation::query()->where('provider_id', $aP->id)->pluck('locale')->all();
            if ($eT !== ['en'] || $aT !== ['ar']) {
                $codes[] = 'translation_layout';
            }
            foreach (self::CANONICAL_FIELDS as $f) {
                if ((string) $eP->getAttribute($f) !== (string) $aP->getAttribute($f)) {
                    $codes[] = 'canonical_conflict:'.$f;
                }
            }
            if (! isset($types[(string) ($p['type_key'] ?? '')]) || (int) $types[(string) $p['type_key']] !== (int) $eP->provider_type_id) {
                $codes[] = 'type_key_mismatch';
            }
            if ($eP->phone === null) {
                $codes[] = 'phone_missing';
            }

            // One piece of location evidence (link + its pin): it must belong to these two records only.
            $self = ['p'.$eP->id, 'p'.$aP->id];
            if ($eP->map_url === null || array_diff($mapUse[$eP->map_url] ?? [], $self) !== []) {
                $codes[] = 'map_url_shared';
            }
            if ($eP->latitude !== null && $eP->longitude !== null && array_diff($pinUse[$this->pin($eP->latitude, $eP->longitude)] ?? [], $self) !== []) {
                $codes[] = 'coordinates_shared';
            }

            // Independent branch evidence: corroborated city correspondence and compatible numbers.
            $ec = $this->cityKey($eRow->payload['city'] ?? null);
            $ac = $this->cityKey($aRow->payload['city'] ?? null);
            if ($ec === null || $ac === null) {
                $codes[] = 'city_missing';
            } elseif (($cityPairs[$ec][$ac] ?? 0) < 2 || $this->dominant($cityPairs[$ec]) !== $ac || $this->dominant($reverse[$ac]) !== $ec) {
                $codes[] = 'city_not_corroborated';
            }
            $ed = $this->placeKey($eRow->payload['district'] ?? null);
            $ad = $this->placeKey($aRow->payload['district'] ?? null);
            // A district that merely repeats the city says nothing about the branch.
            $ed = $ed === $ec ? null : $ed;
            $ad = $ad === $ac ? null : $ad;
            if ($ed !== null && $ad !== null && ! $this->districtCorroborated($ed, $ad, $districtPairs, $districtReverse)) {
                $codes[] = 'district_not_corroborated';
            }
            $eNum = $this->numbers($eRow->payload['address'] ?? null);
            $aNum = $this->numbers($aRow->payload['address'] ?? null);
            if ($eNum !== [] && $aNum !== [] && array_intersect($eNum, $aNum) === []) {
                $codes[] = 'address_numbers_disjoint';
            }

            // Another open suggestion that pairs either row through the SAME location evidence.
            $others = LegacyPairSuggestion::query()->where('migration_batch_id', $batch->id)
                ->where('status', LegacyPairSuggestion::STATUS_SUGGESTED)
                ->where(fn ($q) => $q->where('left_map_id', $eMap->id)->orWhere('right_map_id', $aMap->id))
                ->get()->reject(fn (LegacyPairSuggestion $s) => $s->left_map_id === $eMap->id && $s->right_map_id === $aMap->id);
            foreach ($others as $o) {
                if (array_intersect((array) $o->signals, [PairSuggester::SIGNAL_MAP, PairSuggester::SIGNAL_COORDINATES]) !== []) {
                    $codes[] = 'conflicting_location_suggestion';
                    break;
                }
            }

            $codes = array_values(array_unique($codes));
            if ($codes !== []) {
                $out[] = $entry + ['outcome' => 'held', 'codes' => $codes];

                continue;
            }
            $suggestion = LegacyPairSuggestion::query()->where('left_map_id', $eMap->id)->where('right_map_id', $aMap->id)->first();
            if ($suggestion !== null && $suggestion->status !== LegacyPairSuggestion::STATUS_SUGGESTED) {
                // A human already decided this exact pair (e.g. rejected it): the manifest never overrides that.
                $out[] = $entry + ['outcome' => 'held', 'codes' => ['suggestion_already_reviewed:'.$suggestion->status]];

                continue;
            }
            if ($dryRun) {
                $out[] = $entry + ['outcome' => 'would_apply', 'codes' => [], 'superseded' => $others->count()];

                continue;
            }

            if ($suggestion === null) {
                $signals = [self::SIGNAL_MANIFEST, PairSuggester::SIGNAL_MAP];
                if ($eP->latitude !== null) {
                    $signals[] = PairSuggester::SIGNAL_COORDINATES;
                }
                if ($this->normalizer->phoneKey($eP->phone) !== null) {
                    $signals[] = PairSuggester::SIGNAL_PHONE;
                }
                if ($eId === $aId) {
                    $signals[] = PairSuggester::SIGNAL_SOURCE_ID;
                }
                sort($signals);
                $suggestion = LegacyPairSuggestion::query()->create([
                    'migration_batch_id' => $batch->id, 'left_map_id' => $eMap->id, 'right_map_id' => $aMap->id,
                    'signals' => $signals, 'signal_count' => count($signals), 'status' => LegacyPairSuggestion::STATUS_SUGGESTED,
                ]);
            }

            try {
                $this->pairs->approve($actor ?? throw new LegacyException('An actor is required.'), $suggestion, sprintf(
                    'Owner review manifest %s (2026-11-23), EN#%d/AR#%d re-verified on V2: unique map link and pin, identical phone/type/e-mail/website/logo, corroborated city, compatible address numbers, unchanged source rows. Applied on the owner\'s instruction.',
                    substr($this->manifestSha, 0, 12), $eId, $aId,
                ));
            } catch (LegacyException) {
                // The review service refused (state changed meanwhile): the pair stays exactly as it was.
                $out[] = $entry + ['outcome' => 'held', 'codes' => ['review_service_refused']];

                continue;
            }

            foreach ($others as $o) {
                $this->pairs->reject($actor, $o, sprintf('Superseded: this row was paired through owner review manifest %s (suggestion #%d). Reversible via undo of that pair.', substr($this->manifestSha, 0, 12), $suggestion->id));
                $superseded++;
            }
            $out[] = $entry + ['outcome' => 'applied', 'codes' => [], 'suggestion_id' => $suggestion->id, 'superseded' => $others->count()];
        }

        return [$out, $superseded];
    }

    // ---------------------------------------------------------------- helpers

    /** @return Collection<int, LegacyStagingRow> */
    private function stagedRows(LegacyMigrationBatch $batch, string $table)
    {
        return LegacyStagingRow::query()->where('migration_batch_id', $batch->id)->where('source_table', $table)->orderBy('id')->get();
    }

    private function stagedRow(LegacyMigrationBatch $batch, string $table, int $id): ?LegacyStagingRow
    {
        return LegacyStagingRow::query()->where('migration_batch_id', $batch->id)->where('source_table', $table)->where('source_id', $id)->first();
    }

    private function arabicTypeId(LegacyMigrationBatch $batch, int $arSourceId): ?int
    {
        $row = $this->stagedRow($batch, 'arabic_providers', $arSourceId);
        if ($row === null) {
            return null;
        }
        $this->arabicMapper ??= new TypeMapper($batch->mapping_version);
        $type = $this->arabicMapper->resolve($this->normalizer->clean($row->payload['type'] ?? null));

        return $type['status'] === TypeMapper::APPROVED && $type['type'] !== null ? (int) $type['type'] : null;
    }

    private function cityKey(mixed $city): ?string
    {
        return $this->placeKey($city);
    }

    /**
     * Comparison key for a place name: NFKC, case-folded, Arabic diacritics/tatweel removed, common
     * Arabic letter variants unified, punctuation dropped, spaces collapsed. Never stored.
     */
    private function placeKey(mixed $value): ?string
    {
        $c = $this->normalizer->clean($value);
        if ($c === null) {
            return null;
        }
        $c = \Normalizer::normalize($c, \Normalizer::FORM_KC) ?: $c;
        $c = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $c);
        $c = strtr($c, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $c = (string) preg_replace('/[\p{P}\p{S}]+/u', ' ', mb_strtolower($c));
        $c = trim((string) preg_replace('/\s+/u', ' ', $c));

        return $c === '' ? null : $c;
    }

    /**
     * @param  array<string, array<string, int>>  $pairs
     * @return array<string, array<string, int>>
     */
    private function invert(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $a => $bs) {
            foreach ($bs as $b => $n) {
                $out[(string) $b][(string) $a] = $n;
            }
        }

        return $out;
    }

    /**
     * A district pair is accepted only on POSITIVE evidence (a branch-level check independent of
     * the map link): the same EN/AR district correspondence occurs in at least two reviewed pairs,
     * or one name refines the clear correspondence of the other ("Nasr City" / "east Nasr City
     * district"), or the two names are transliterations of each other (consonant skeletons).
     * Anything else, e.g. a facility name or a different area in one language, stays for a human.
     *
     * @param  array<string, array<string, int>>  $forward
     * @param  array<string, array<string, int>>  $reverse
     */
    private function districtCorroborated(string $en, string $ar, array $forward, array $reverse): bool
    {
        if (($forward[$en][$ar] ?? 0) >= 2) {
            return true;
        }
        $refines = function (?string $a, string $b): bool {
            if ($a === null) {
                return false;
            }
            $x = explode(' ', $a);
            $y = explode(' ', $b);

            return array_diff($x, $y) === [] || array_diff($y, $x) === [];
        };
        $d = $this->dominant($forward[$en] ?? []);
        $r = $this->dominant($reverse[$ar] ?? []);
        if (($d !== null && ($forward[$en][$d] ?? 0) >= 2 && $refines($d, $ar)) || ($r !== null && ($reverse[$ar][$r] ?? 0) >= 2 && $refines($r, $en))) {
            return true;
        }

        $x = self::englishSkeleton($en);
        $y = self::arabicSkeleton($ar);
        if ($x === '' || $y === '') {
            return false;
        }
        similar_text($x, $y, $percent);

        return $percent >= 60.0;
    }

    /** Consonant skeleton of a (normalized) English place name, for transliteration comparison. */
    public static function englishSkeleton(string $name): string
    {
        $tokens = array_filter(explode(' ', $name), fn ($t) => $t !== '' && ! in_array($t, ['el', 'al', 'district', 'city', 'center', 'centre', 'new', 'old'], true));
        $s = strtr(implode('', $tokens), ['sh' => 's', 'kh' => 'k', 'th' => 't', 'gh' => 'g', 'ph' => 'f']);
        $s = strtr($s, ['p' => 'b', 'v' => 'f', 'q' => 'k', 'c' => 'k', 'j' => 'g', 'x' => 'ks', 'a' => '', 'e' => '', 'i' => '', 'o' => '', 'u' => '', 'y' => '', 'w' => '']);
        $s = (string) preg_replace('/[^a-z0-9]/', '', $s);

        return (string) preg_replace('/(.)\1+/', '$1', $s);
    }

    /** Consonant skeleton of a (normalized) Arabic place name, mapped to the same Latin classes. */
    public static function arabicSkeleton(string $name): string
    {
        $map = ['ب' => 'b', 'ت' => 't', 'ث' => 't', 'ج' => 'g', 'ح' => 'h', 'خ' => 'k', 'د' => 'd', 'ذ' => 'd', 'ر' => 'r', 'ز' => 'z',
            'س' => 's', 'ش' => 's', 'ص' => 's', 'ض' => 'd', 'ط' => 't', 'ظ' => 'z', 'ع' => '', 'غ' => 'g', 'ف' => 'f', 'ق' => 'k',
            'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'ه' => 'h', 'و' => '', 'ي' => '', 'ا' => '', 'ء' => ''];
        $out = '';
        foreach (explode(' ', $name) as $token) {
            if ($token === '' || in_array($token, ['حي', 'مدينه', 'مركز', 'قسم'], true)) {
                continue;
            }
            if (mb_strlen($token) > 3 && str_starts_with($token, 'ال')) {
                $token = mb_substr($token, 2);
            }
            foreach (mb_str_split($token) as $ch) {
                $out .= $map[$ch] ?? (ctype_digit($ch) ? $ch : '');
            }
        }

        return (string) preg_replace('/(.)\1+/', '$1', $out);
    }

    /** @param array<string, int> $counts */
    private function dominant(array $counts): ?string
    {
        arsort($counts);
        $keys = array_keys($counts);
        if ($keys === [] || (isset($keys[1]) && $counts[$keys[0]] === $counts[$keys[1]])) {
            return null;
        }

        return (string) $keys[0];
    }

    /** @return list<string> digit groups of an address, Arabic-Indic digits mapped to ASCII */
    private function numbers(mixed $address): array
    {
        $a = $this->normalizer->clean($address);
        if ($a === null) {
            return [];
        }
        $a = strtr($a, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        preg_match_all('/\d+/', $a, $m);

        return array_values(array_unique(array_map(fn ($n) => ltrim($n, '0') === '' ? '0' : ltrim($n, '0'), $m[0])));
    }

    private function pin(float|string $lat, float|string $lng): string
    {
        return number_format((float) $lat, 5, '.', '').','.number_format((float) $lng, 5, '.', '');
    }
}
