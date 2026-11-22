<?php

namespace App\Services\Legacy;

use App\Enums\Permission;
use App\Models\LegacyIdMap;
use App\Models\LegacyMigrationBatch;
use App\Models\LegacyPairSuggestion;
use App\Models\Provider;
use App\Models\ProviderTranslation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Step 6: the ONLY place where two single-locale providers become one canonical provider.
 *
 * A human with `migration.review` approves a suggestion; nothing in the pipeline approves
 * automatically. Approve is fully reversible:
 *   - the Arabic translation row is MOVED (not copied) onto the English provider,
 *   - the now-empty Arabic provider is soft-deleted (never hard-deleted),
 *   - the canonical values stay those of the English provider; every differing Arabic-side
 *     value (phone, map, type ...) is preserved in the id map's review_evidence,
 *   - both prior provider ids and the moved translation id are recorded so undo() can re-split.
 * Audit metadata carries ids, signal names and field NAMES only, never provider values.
 */
class PairReviewService
{
    /** Canonical columns compared on approval (English provider wins; the rest is kept as evidence). */
    private const CANONICAL_FIELDS = ['provider_type_id', 'phone', 'email', 'website', 'map_url', 'latitude', 'longitude', 'logo_path', 'is_active'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function approve(User $actor, LegacyPairSuggestion $suggestion): void
    {
        $this->authorize($actor);

        $summary = DB::transaction(function () use ($actor, $suggestion) {
            $s = LegacyPairSuggestion::query()->lockForUpdate()->findOrFail($suggestion->getKey());
            if ($s->status !== LegacyPairSuggestion::STATUS_SUGGESTED) {
                throw new LegacyException('This suggestion has already been reviewed.');
            }

            $left = LegacyIdMap::query()->lockForUpdate()->findOrFail($s->left_map_id);
            $right = LegacyIdMap::query()->lockForUpdate()->findOrFail($s->right_map_id);

            if ($left->status !== LegacyIdMap::STATUS_UNPAIRED || $right->status !== LegacyIdMap::STATUS_UNPAIRED
                || $left->provider_id === null || $right->provider_id === null || $left->provider_id === $right->provider_id
                || $left->source_table !== 'providers' || $right->source_table !== 'arabic_providers') {
                throw new LegacyException('One of the two source rows is no longer an unpaired English/Arabic provider.');
            }

            $en = Provider::query()->lockForUpdate()->find($left->provider_id);
            $ar = Provider::query()->lockForUpdate()->find($right->provider_id);
            if ($en === null || $ar === null) {
                throw new LegacyException('A provider of this pair no longer exists or was deleted.');
            }

            $moved = ProviderTranslation::query()->where('provider_id', $ar->id)->where('locale', 'ar')->first();
            if ($moved === null) {
                throw new LegacyException('The Arabic provider has no Arabic translation to move.');
            }
            if (ProviderTranslation::query()->where('provider_id', $en->id)->where('locale', 'ar')->exists()) {
                throw new LegacyException('The English provider already has an Arabic translation.');
            }

            // Conflicting canonical values: English wins, the Arabic-side values are evidence.
            $discarded = [];
            foreach (self::CANONICAL_FIELDS as $field) {
                $arValue = $ar->getAttribute($field);
                if ($arValue !== null && (string) $en->getAttribute($field) !== (string) $arValue) {
                    $discarded[$field] = is_bool($arValue) ? (int) $arValue : $arValue;
                }
            }

            $moved->forceFill(['provider_id' => $en->id])->save();
            $ar->delete();
            $enRev = $this->bump($en, $left->migration_batch_id);
            $arRev = $this->bump($ar, $left->migration_batch_id);

            $now = now();
            $common = ['suggestion_id' => $s->id, 'signals' => $s->signals];
            $left->forceFill([
                'status' => LegacyIdMap::STATUS_APPROVED, 'match_method' => 'manual_review',
                'reviewed_by' => $actor->getKey(), 'reviewed_at' => $now,
                'review_evidence' => $common + ['action' => 'approved', 'paired_with_map_id' => $right->id, 'revision_after' => $enRev],
            ])->save();
            $right->forceFill([
                'provider_id' => $en->id, 'status' => LegacyIdMap::STATUS_APPROVED, 'match_method' => 'manual_review',
                'reviewed_by' => $actor->getKey(), 'reviewed_at' => $now,
                'review_evidence' => $common + [
                    'action' => 'approved',
                    'paired_with_map_id' => $left->id,
                    'prior_provider_id' => $ar->id,
                    'prior_provider_revision' => $arRev,
                    'merged_into_provider_id' => $en->id,
                    'moved_translation_id' => $moved->id,
                    'discarded_ar_values' => $discarded,
                ],
            ])->save();
            $s->forceFill(['status' => LegacyPairSuggestion::STATUS_APPROVED, 'reviewed_by' => $actor->getKey(), 'reviewed_at' => $now])->save();

            return [
                'suggestion_id' => $s->id, 'left_map_id' => $left->id, 'right_map_id' => $right->id,
                'signals' => $s->signals, 'conflict_fields' => array_keys($discarded),
            ];
        });

        $this->audit->record('migration.pair.approved', ['legacy_pair_suggestion', $summary['suggestion_id']], 'success', $summary, $actor);
    }

    public function reject(User $actor, LegacyPairSuggestion $suggestion): void
    {
        $this->authorize($actor);

        DB::transaction(function () use ($actor, $suggestion) {
            $s = LegacyPairSuggestion::query()->lockForUpdate()->findOrFail($suggestion->getKey());
            if ($s->status !== LegacyPairSuggestion::STATUS_SUGGESTED) {
                throw new LegacyException('This suggestion has already been reviewed.');
            }
            // The rows stay unpaired: rejecting a suggestion never touches providers or id maps.
            $s->forceFill(['status' => LegacyPairSuggestion::STATUS_REJECTED, 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now()])->save();
        });

        $this->audit->record('migration.pair.rejected', ['legacy_pair_suggestion', $suggestion->getKey()], 'success', ['suggestion_id' => $suggestion->getKey()], $actor);
    }

    /** Re-split an approved pair: restore the Arabic provider and move its translation back. */
    public function undo(User $actor, LegacyPairSuggestion $suggestion): void
    {
        $this->authorize($actor);

        $summary = DB::transaction(function () use ($actor, $suggestion) {
            $s = LegacyPairSuggestion::query()->lockForUpdate()->findOrFail($suggestion->getKey());
            if ($s->status !== LegacyPairSuggestion::STATUS_APPROVED) {
                throw new LegacyException('Only an approved pair can be undone.');
            }

            $left = LegacyIdMap::query()->lockForUpdate()->findOrFail($s->left_map_id);
            $right = LegacyIdMap::query()->lockForUpdate()->findOrFail($s->right_map_id);
            $evidence = (array) $right->review_evidence;

            if ($left->status !== LegacyIdMap::STATUS_APPROVED || $right->status !== LegacyIdMap::STATUS_APPROVED
                || ! isset($evidence['prior_provider_id'], $evidence['moved_translation_id'])) {
                throw new LegacyException('The approval evidence needed to undo this pair is missing.');
            }

            $en = Provider::query()->lockForUpdate()->find($left->provider_id);
            $ar = Provider::query()->withTrashed()->lockForUpdate()->find((int) $evidence['prior_provider_id']);
            $translation = ProviderTranslation::query()->find((int) $evidence['moved_translation_id']);

            if ($en === null || $ar === null || ! $ar->trashed()
                || $translation === null || $translation->provider_id !== $en->id || $translation->locale !== 'ar') {
                throw new LegacyException('The merged providers were changed after approval; undo refused.');
            }

            $translation->forceFill(['provider_id' => $ar->id])->save();
            $ar->restore();
            $this->bump($en, $left->migration_batch_id);
            $this->bump($ar->refresh(), $left->migration_batch_id);

            $note = ['action' => 'undone', 'undone_suggestion_id' => $s->id, 'undone_by' => $actor->getKey(), 'undone_at' => now()->toIso8601String()];
            $left->forceFill([
                'status' => LegacyIdMap::STATUS_UNPAIRED, 'match_method' => null,
                'reviewed_by' => null, 'reviewed_at' => null, 'review_evidence' => $note,
            ])->save();
            $right->forceFill([
                'provider_id' => $ar->id, 'status' => LegacyIdMap::STATUS_UNPAIRED, 'match_method' => null,
                'reviewed_by' => null, 'reviewed_at' => null, 'review_evidence' => $note,
            ])->save();
            $s->forceFill(['status' => LegacyPairSuggestion::STATUS_SUGGESTED, 'reviewed_by' => null, 'reviewed_at' => null])->save();

            return ['suggestion_id' => $s->id, 'left_map_id' => $left->id, 'right_map_id' => $right->id];
        });

        $this->audit->record('migration.pair.undone', ['legacy_pair_suggestion', $summary['suggestion_id']], 'success', $summary, $actor);
    }

    private function authorize(User $actor): void
    {
        // Pair review changes canonical data: LOCAL-ONLY and only against an allow-listed destination.
        LegacyGuard::assertNotProduction();
        if (! Gate::forUser($actor)->allows(Permission::MigrationReview->value)) {
            $this->audit->record('authorization.denied', null, 'denied', ['permission' => Permission::MigrationReview->value], $actor);
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    /**
     * Revision bump for a merge/undo. The expected extra revisions are counted on the batch so
     * rollback can tell pipeline edits from later human edits (revision above the expectation).
     */
    private function bump(Provider $provider, int $batchId): int
    {
        $provider->forceFill(['revision' => $provider->revision + 1])->save();

        $batch = LegacyMigrationBatch::query()->lockForUpdate()->find($batchId);
        if ($batch !== null) {
            $counts = $batch->counts ?? [];
            $counts['revision_bumps'][(string) $provider->id] = ($counts['revision_bumps'][(string) $provider->id] ?? 0) + 1;
            $batch->forceFill(['counts' => $counts])->save();
        }

        return $provider->revision;
    }
}
