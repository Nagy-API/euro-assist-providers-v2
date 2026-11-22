<?php

namespace App\Services\Legacy;

use App\Models\FeaturedProvider;
use App\Models\LegacyIdMap;
use App\Models\LegacyMigrationBatch;
use App\Models\LegacyStagingRow;
use App\Models\Provider;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ProviderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Step 2-4 and 9: classify every staged source row into exactly one state and create the target
 * records for the mapped ones.
 *
 * - Every source locale row becomes ONE canonical provider with ONE translation, status
 *   `unpaired`. Numeric id, name, phone or map-URL equality NEVER merges rows (that is only ever
 *   suggested, then approved by a human reviewer).
 * - Providers are written through ProviderService (validation, revision, audit are identical to
 *   every other write path). Source rows that fail validation are not truncated or "fixed": they
 *   go to `needs_review` with a reason code naming the failing fields (never values).
 * - Featured entries are brand-level records; logos are NOT copied (decision D8 deferred), the raw
 *   reference stays in the staging payload only.
 *
 * Re-running is safe: mapped rows are skipped; needs_review rows are re-evaluated (e.g. after a
 * type mapping was approved).
 */
class LegacyApplier
{
    public function __construct(
        private readonly ProviderService $providers,
        private readonly RowNormalizer $normalizer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{states: array<string, array<string, int>>, reasons: array<string, int>, dry_run: bool}
     */
    public function apply(LegacyMigrationBatch $batch, ?User $actor, bool $dryRun, bool $acceptProvisionalTypes = false): array
    {
        LegacyGuard::assertRunnable($batch->snapshot_sha256);

        if (! in_array($batch->status, ['staged', 'applied'], true)) {
            throw new LegacyException("Batch status is '{$batch->status}'; it cannot be applied.");
        }
        if (! $dryRun && (! $actor instanceof User || ! $actor->isAdmin())) {
            throw new LegacyException('Applying a batch requires --actor=<e-mail of an active Admin account>.');
        }

        $mapper = new TypeMapper($batch->mapping_version, $acceptProvisionalTypes);
        $states = [];
        $reasons = [];

        LegacyStagingRow::query()
            ->where('migration_batch_id', $batch->id)
            ->whereIn('state', [LegacyStagingRow::STATE_STAGED, LegacyStagingRow::STATE_NEEDS_REVIEW])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($batch, $actor, $dryRun, $mapper, &$states, &$reasons) {
                foreach ($rows as $row) {
                    [$state, $reason] = $this->processRow($batch, $row, $mapper, $actor, $dryRun);
                    $states[$row->source_table][$state] = ($states[$row->source_table][$state] ?? 0) + 1;
                    if ($reason !== null) {
                        $key = explode(':', $reason, 2)[0];
                        $reasons[$key] = ($reasons[$key] ?? 0) + 1;
                    }
                }
            });

        if (! $dryRun) {
            $counts = $batch->counts ?? [];
            $counts['states'] = $this->stateCounts($batch);
            $batch->forceFill([
                'status' => 'applied',
                'applied_at' => $batch->applied_at ?? now(),
                'counts' => $counts,
            ])->save();

            $this->audit->record('migration.batch.applied', $batch, 'success', [
                'batch_uuid' => $batch->uuid,
                'mapped' => $this->totalFor($counts['states'], LegacyStagingRow::STATE_MAPPED),
                'needs_review' => $this->totalFor($counts['states'], LegacyStagingRow::STATE_NEEDS_REVIEW),
                'rejected' => $this->totalFor($counts['states'], LegacyStagingRow::STATE_REJECTED),
            ], $actor);
        }

        ksort($reasons);

        return ['states' => $states, 'reasons' => $reasons, 'dry_run' => $dryRun];
    }

    /**
     * @return array{0: string, 1: ?string} state, reason code
     */
    private function processRow(LegacyMigrationBatch $batch, LegacyStagingRow $row, TypeMapper $mapper, ?User $actor, bool $dryRun): array
    {
        $kind = config("legacy.sources.{$row->source_table}.kind");

        [$state, $reason, $payload] = $kind === 'featured'
            ? $this->classifyFeatured($row, $mapper)
            : $this->classifyProvider($row, $mapper);

        if ($state === LegacyStagingRow::STATE_MAPPED) {
            $existing = LegacyIdMap::query()->where('source_table', $row->source_table)->where('source_id', $row->source_id)->first();
            if ($existing !== null && $existing->migration_batch_id !== $batch->id) {
                [$state, $reason, $payload] = [LegacyStagingRow::STATE_NEEDS_REVIEW, 'already_mapped_other_batch', null];
            } elseif ($existing !== null) {
                $payload = null; // already created by this batch: just record the state
            }

            if ($state === LegacyStagingRow::STATE_MAPPED && ! $dryRun) {
                DB::transaction(function () use ($batch, $row, $actor, $kind, $payload, $existing) {
                    if ($existing === null) {
                        $kind === 'featured'
                            ? $this->createFeatured($batch, $row, $actor, (array) $payload)
                            : $this->createProvider($batch, $row, $actor, (array) $payload);
                    }
                });
            }
        }

        if (! $dryRun) {
            $row->forceFill(['state' => $state, 'reason' => $reason])->save();
        }

        return [$state, $reason];
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?array<string, mixed>} state, reason, ProviderService input
     */
    private function classifyProvider(LegacyStagingRow $row, TypeMapper $mapper): array
    {
        $locale = (string) $row->source_locale;
        $n = $this->normalizer->provider((array) $row->payload);
        $text = $n['translation'];

        if ($text['name'] === null) {
            return [LegacyStagingRow::STATE_REJECTED, 'missing_name', null];
        }

        $type = $mapper->resolve($n['type_raw']);
        if (! in_array($type['status'], [TypeMapper::APPROVED, TypeMapper::PROVISIONAL], true)) {
            return [LegacyStagingRow::STATE_NEEDS_REVIEW, 'type_'.$type['status'], null];
        }
        if ($text['address'] === null) {
            return [LegacyStagingRow::STATE_NEEDS_REVIEW, 'missing_address', null];
        }

        $input = [
            'provider_type_id' => $type['type'],
            'phone' => $n['phone'],
            'email' => $n['email'],
            'website' => $n['website'],
            'map_url' => $n['map_url'],
            'latitude' => $n['latitude'],
            'longitude' => $n['longitude'],
            'logo_path' => $n['logo_path'],
            'is_active' => true,
            'translations' => [$locale => $text],
        ];

        try {
            $this->providers->validate($input, creating: true);
        } catch (ValidationException $e) {
            $fields = array_keys($e->errors());
            sort($fields);

            return [LegacyStagingRow::STATE_NEEDS_REVIEW, mb_substr('validation_failed:'.implode(',', $fields), 0, 190), null];
        }

        // A mapped row that relied on a provisional type mapping stays traceable (reason code).
        return [LegacyStagingRow::STATE_MAPPED, $type['status'] === TypeMapper::PROVISIONAL ? 'provisional_type' : null, $input];
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?array<string, mixed>}
     */
    private function classifyFeatured(LegacyStagingRow $row, TypeMapper $mapper): array
    {
        $n = $this->normalizer->featured((array) $row->payload);

        if ($n['name_en'] === null && $n['name_ar'] === null) {
            return [LegacyStagingRow::STATE_REJECTED, 'missing_name', null];
        }

        $resolved = [];
        $provisional = false;
        foreach ([$n['type_en_raw'], $n['type_ar_raw']] as $raw) {
            if ($raw === null) {
                continue;
            }
            $type = $mapper->resolve($raw);
            if (! in_array($type['status'], [TypeMapper::APPROVED, TypeMapper::PROVISIONAL], true)) {
                return [LegacyStagingRow::STATE_NEEDS_REVIEW, 'type_'.$type['status'], null];
            }
            $resolved[] = $type['type'];
            $provisional = $provisional || $type['status'] === TypeMapper::PROVISIONAL;
        }
        if (count(array_unique($resolved)) > 1) {
            return [LegacyStagingRow::STATE_NEEDS_REVIEW, 'type_conflict', null];
        }

        return [LegacyStagingRow::STATE_MAPPED, $provisional ? 'provisional_type' : null, [
            'name_en' => $n['name_en'],
            'name_ar' => $n['name_ar'],
            'provider_type_id' => $resolved[0] ?? null,
        ]];
    }

    /** @param array<string, mixed> $input */
    private function createProvider(LegacyMigrationBatch $batch, LegacyStagingRow $row, ?User $actor, array $input): void
    {
        $provider = $this->providers->create($actor ?? throw new LegacyException('An actor is required.'), $input);

        Provider::query()->whereKey($provider->getKey())->update(['migration_batch_id' => $batch->id]);

        LegacyIdMap::query()->create([
            'source_table' => $row->source_table,
            'source_id' => $row->source_id,
            'source_locale' => $row->source_locale,
            'provider_id' => $provider->getKey(),
            'status' => LegacyIdMap::STATUS_UNPAIRED,
            'match_method' => null,
            'snapshot_sha256' => $batch->snapshot_sha256,
            'migration_batch_id' => $batch->id,
        ]);
    }

    /** @param array<string, mixed> $input */
    private function createFeatured(LegacyMigrationBatch $batch, LegacyStagingRow $row, ?User $actor, array $input): void
    {
        $featured = FeaturedProvider::query()->create([
            'name_en' => $input['name_en'],
            'name_ar' => $input['name_ar'],
            'provider_type_id' => $input['provider_type_id'],
            'logo_path' => null, // media is not copied (D8 deferred); raw reference stays in staging
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $featured->forceFill(['created_by' => $actor?->getKey()])->save();

        LegacyIdMap::query()->create([
            'source_table' => $row->source_table,
            'source_id' => $row->source_id,
            'source_locale' => null,
            'featured_provider_id' => $featured->getKey(),
            'status' => LegacyIdMap::STATUS_UNPAIRED,
            'match_method' => null,
            'snapshot_sha256' => $batch->snapshot_sha256,
            'migration_batch_id' => $batch->id,
        ]);
    }

    /** @return array<string, array<string, int>> source_table => state => count */
    private function stateCounts(LegacyMigrationBatch $batch): array
    {
        $out = [];
        $rows = LegacyStagingRow::query()->where('migration_batch_id', $batch->id)
            ->selectRaw('source_table, state, count(*) as c')->groupBy('source_table', 'state')
            ->orderBy('source_table')->orderBy('state')->get();
        foreach ($rows as $r) {
            $out[$r->source_table][$r->state] = (int) $r->getAttribute('c');
        }

        return $out;
    }

    /** @param array<string, array<string, int>> $states */
    private function totalFor(array $states, string $state): int
    {
        return (int) array_sum(array_map(fn (array $t) => $t[$state] ?? 0, $states));
    }
}
