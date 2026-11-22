<?php

namespace App\Services;

use App\Models\FeaturedProvider;
use App\Models\LegacyIdMap;
use App\Models\LegacyPairSuggestion;
use App\Models\LegacyStagingRow;
use App\Models\Provider;
use App\Models\ProviderType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard metrics, computed from canonical V2 data only (never from legacy staging tables).
 *
 * Definitions (also shown to the user as help text):
 *  - A "provider record" is one canonical location/branch row in `providers` (ADR-009). The same
 *    company with several branches counts once per branch; this is NOT a count of unique companies.
 *  - the total, active, inactive, missing-value and translation counts exclude soft-deleted rows.
 *  - deleted counts soft-deleted rows only.
 *  - missing_city: no translation has a non-empty city. missing_phone: phone is NULL or empty.
 *  - Translation coverage counts providers by which locales have a translation row.
 *
 * Everything is a bounded SQL aggregate (one scan for the core counters, one grouped query for types,
 * one LIMITed list); no provider rows are loaded except the 10 most recently updated.
 */
class DashboardStats
{
    public const TOP_TYPES = 8;

    public const RECENT_LIMIT = 10;

    /**
     * @return array{
     *   total: int, active: int, inactive: int, deleted: int|null,
     *   missing_city: int, missing_phone: int,
     *   translations: array{ar: int, en: int, both: int, only_ar: int, only_en: int, none: int},
     *   featured: array{total: int, active: int},
     * }
     */
    public function counters(bool $includeDeleted): array
    {
        $arExists = "exists (select 1 from provider_translations pt where pt.provider_id = providers.id and pt.locale = 'ar')";
        $enExists = "exists (select 1 from provider_translations pt where pt.provider_id = providers.id and pt.locale = 'en')";
        $cityExists = "exists (select 1 from provider_translations pt where pt.provider_id = providers.id and pt.city is not null and pt.city <> '')";

        $row = Provider::query()->toBase()->whereNull('providers.deleted_at')->selectRaw(
            "count(*) as total,
             coalesce(sum(case when providers.is_active = 1 then 1 else 0 end), 0) as active,
             coalesce(sum(case when providers.phone is null or providers.phone = '' then 1 else 0 end), 0) as missing_phone,
             coalesce(sum(case when not {$cityExists} then 1 else 0 end), 0) as missing_city,
             coalesce(sum(case when {$arExists} then 1 else 0 end), 0) as with_ar,
             coalesce(sum(case when {$enExists} then 1 else 0 end), 0) as with_en,
             coalesce(sum(case when {$arExists} and {$enExists} then 1 else 0 end), 0) as with_both,
             coalesce(sum(case when not {$arExists} and not {$enExists} then 1 else 0 end), 0) as with_none"
        )->first();

        $total = (int) $row->total;
        $ar = (int) $row->with_ar;
        $en = (int) $row->with_en;
        $both = (int) $row->with_both;

        $featured = FeaturedProvider::query()->toBase()->selectRaw(
            'count(*) as total, coalesce(sum(case when is_active = 1 then 1 else 0 end), 0) as active'
        )->first();

        return [
            'total' => $total,
            'active' => (int) $row->active,
            'inactive' => $total - (int) $row->active,
            'deleted' => $includeDeleted ? Provider::onlyTrashed()->count() : null,
            'missing_city' => (int) $row->missing_city,
            'missing_phone' => (int) $row->missing_phone,
            'translations' => [
                'ar' => $ar,
                'en' => $en,
                'both' => $both,
                'only_ar' => $ar - $both,
                'only_en' => $en - $both,
                'none' => (int) $row->with_none,
            ],
            'featured' => ['total' => (int) $featured->total, 'active' => (int) $featured->active],
        ];
    }

    /**
     * Providers per type: the $top largest types plus one "other" bucket. Counts are canonical
     * non-deleted provider records.
     *
     * @return array{types: list<array{id: int, label: string, count: int}>, other: int, other_types: int}
     */
    public function perType(?string $locale = null, int $top = self::TOP_TYPES): array
    {
        $grouped = DB::table('providers')
            ->whereNull('deleted_at')
            ->select('provider_type_id', DB::raw('count(*) as aggregate_count'))
            ->groupBy('provider_type_id')
            ->orderByDesc('aggregate_count')
            ->orderBy('provider_type_id')
            ->limit(500) // safety bound; provider types are a small controlled vocabulary
            ->get();

        $topRows = $grouped->take($top);
        $rest = $grouped->slice($top);
        $labels = ProviderType::query()->with('translations')->whereIn('id', $topRows->pluck('provider_type_id'))->get()->keyBy('id');

        return [
            'types' => $topRows->map(fn ($r) => [
                'id' => (int) $r->provider_type_id,
                'label' => $labels->get($r->provider_type_id)?->label($locale) ?? '—',
                'count' => (int) $r->aggregate_count,
            ])->values()->all(),
            'other' => (int) $rest->sum('aggregate_count'),
            'other_types' => $rest->count(),
        ];
    }

    /**
     * The most recently updated provider records (bounded).
     *
     * @return Collection<int, Provider>
     */
    public function recentlyUpdated(int $limit = self::RECENT_LIMIT): Collection
    {
        return Provider::query()
            ->with(['translations', 'type.translations'])
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(min($limit, self::RECENT_LIMIT))
            ->get();
    }

    /**
     * Legacy migration review backlog (counts only). Reads the local migration bookkeeping tables,
     * never the legacy source.
     *
     * @return array{unpaired: int, suggestions: int, held: int, provisional: int}
     */
    public function legacyBacklog(): array
    {
        return [
            'unpaired' => LegacyIdMap::query()->where('status', LegacyIdMap::STATUS_UNPAIRED)->whereNotNull('provider_id')->count(),
            'suggestions' => LegacyPairSuggestion::query()->where('status', LegacyPairSuggestion::STATUS_SUGGESTED)->count(),
            'held' => LegacyStagingRow::query()->whereIn('state', [LegacyStagingRow::STATE_NEEDS_REVIEW, LegacyStagingRow::STATE_REJECTED])->count(),
            'provisional' => LegacyStagingRow::query()->where('state', LegacyStagingRow::STATE_MAPPED)->where('reason', 'provisional_type')->count(),
        ];
    }

    /**
     * Where the canonical records came from, so the dashboard never presents the record count as a
     * count of unique real-world branches (counts only; readable by every role).
     *
     *  - source_rows: legacy source rows staged by the migration (English + Arabic + featured).
     *  - imported: canonical providers created from a legacy row (a mapping with a provider).
     *  - unpaired: imported providers still waiting for an English/Arabic pairing decision. Each
     *    language of the same branch is a separate record until an administrator pairs them.
     *  - reviewed_pairs: pair suggestions an administrator approved or rejected.
     *
     * @return array{source_rows: int, imported: int, unpaired: int, reviewed_pairs: int}
     */
    public function provenance(): array
    {
        return [
            'source_rows' => LegacyStagingRow::query()->count(),
            'imported' => LegacyIdMap::query()->whereNotNull('provider_id')->count(),
            'unpaired' => LegacyIdMap::query()->where('status', LegacyIdMap::STATUS_UNPAIRED)->whereNotNull('provider_id')->count(),
            'reviewed_pairs' => LegacyPairSuggestion::query()->whereIn('status', [LegacyPairSuggestion::STATUS_APPROVED, LegacyPairSuggestion::STATUS_REJECTED])->count(),
        ];
    }
}
