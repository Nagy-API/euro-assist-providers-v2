<?php

namespace App\Services\LegacyApi;

use App\Models\FeaturedProvider;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Rules\SafeHttpUrl;
use App\Services\Media\ProviderPhotoStore;
use App\Services\Media\TypeIconStore;
use App\Services\Ui\FeaturedLogoStore;
use App\Support\LegacyTypeStyle;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads the CURRENT V2 database and presents it in the shape the old (V1) public API returned:
 * one row per language ("providers" = English rows, "arabic-providers" = Arabic rows).
 *
 * - Only active, non-deleted providers that have a translation in the requested language appear.
 * - Only fields V1 already exposed publicly are produced (explicit allow-list; no user ids, revision,
 *   batch ids, internal paths or inactive records).
 * - `id` is the id the old system issued for that language row (legacy_id_maps), so URLs and
 *   bookmarks of existing consumers keep working. A provider created in V2 gets offset + V2 id.
 * - Nothing is invented: the governorate dropdown of the public site is the `city` value, exactly
 *   as stored; no geographic classification is derived here.
 */
class LegacyProviderReader
{
    public const SOURCE_TABLE = ['en' => 'providers', 'ar' => 'arabic_providers'];

    /** Free-text columns searched by the broad `search` of the V1 list endpoint. */
    private const LIST_SEARCH = ['t.name', 't.address', 'providers.phone'];

    /** Columns searched by the V1 `search` endpoint (`query`). */
    private const FULL_SEARCH = [
        't.name', 'tt.name', 't.specialization', 't.city', 't.district', 't.address', 't.discount_text',
        't.hours', 'providers.phone', 'providers.website', 't.package_text',
    ];

    /** @var list<string> */
    public const FIELD_PROJECTIONS = ['city', 'district', 'type'];

    public function __construct(
        private readonly ProviderPhotoStore $photos,
        private readonly TypeIconStore $icons,
        private readonly FeaturedLogoStore $featuredLogos,
    ) {}

    public static function offset(): int
    {
        return (int) config('providers.legacy_api.unmapped_id_offset', 1000000);
    }

    /** @return Builder<Provider> */
    public function query(string $locale): Builder
    {
        $mapSub = DB::table('legacy_id_maps')->selectRaw('min(source_id)')
            ->whereColumn('legacy_id_maps.provider_id', 'providers.id')
            ->where('legacy_id_maps.source_table', self::SOURCE_TABLE[$locale]);
        $otherSub = DB::table('provider_translations as o')->selectRaw('count(*)')
            ->whereColumn('o.provider_id', 'providers.id')->where('o.locale', '!=', $locale);

        return Provider::query()
            ->join('provider_translations as t', function ($join) use ($locale) {
                $join->on('t.provider_id', '=', 'providers.id')->where('t.locale', '=', $locale);
            })
            ->leftJoin('provider_type_translations as tt', function ($join) use ($locale) {
                $join->on('tt.provider_type_id', '=', 'providers.provider_type_id')->where('tt.locale', '=', $locale);
            })
            ->where('providers.is_active', true)
            ->select([
                'providers.*',
                't.name as t_name', 't.address as t_address', 't.city as t_city', 't.district as t_district',
                't.specialization as t_specialization', 't.hours as t_hours',
                't.discount_text as t_discount', 't.package_text as t_package',
            ])
            ->selectSub($mapSub, 'legacy_lid')
            ->selectSub($otherSub, 'other_locales')
            ->selectRaw('coalesce(('.$mapSub->toSql().'), ? + providers.id) as sort_id', [...$mapSub->getBindings(), self::offset()])
            ->with('type.translations')
            ->orderBy('sort_id')
            ->orderBy('providers.id');
    }

    /**
     * Apply the V1 list filters (LIKE substring semantics, wildcards escaped).
     *
     * @param  Builder<Provider>  $q
     * @param  array<string, mixed>  $in  query-string input
     */
    public function applyListFilters(Builder $q, array $in): void
    {
        $this->applyColumnFilters($q, $in);

        if ($this->filled($in, 'search')) {
            Like::anyOf($q, self::LIST_SEARCH, $this->str($in['search']));
        }

        if ($this->filled($in, 'searchName')) {
            $term = trim((string) preg_replace('/\s+/u', ' ', $this->str($in['searchName'])));
            $q->whereRaw("trim(replace(replace(t.name, '  ', ' '), '  ', ' ')) = ?", [$term]);
        }
    }

    /**
     * @param  Builder<Provider>  $q
     * @param  array<string, mixed>  $in
     */
    public function applyColumnFilters(Builder $q, array $in): void
    {
        if ($this->filled($in, 'type')) {
            $term = $this->str($in['type']);
            // The type label of this language, any other language's spelling, or the type key.
            $q->where(function (Builder $w) use ($term) {
                $w->whereIn('providers.provider_type_id', function ($sub) use ($term) {
                    $sub->select('x.provider_type_id')->from('provider_type_translations as x');
                    Like::anyOfQuery($sub, ['x.name'], $term);
                })->orWhereIn('providers.provider_type_id', function ($sub) use ($term) {
                    $sub->select('k.id')->from('provider_types as k');
                    Like::anyOfQuery($sub, ['k.key'], $term);
                });
            });
        }
        if ($this->filled($in, 'city')) {
            Like::anyOf($q, ['t.city'], $this->str($in['city']));
        }
        if ($this->filled($in, 'district')) {
            Like::anyOf($q, ['t.district'], $this->str($in['district']));
        }
        // `governorate` is NOT a V2 (or V1) data field: V1 queried a column that never existed. It is ignored
        // rather than mapped onto another field, so no geographic classification is invented.
    }

    /**
     * @param  Builder<Provider>  $q
     */
    public function applyFullSearch(Builder $q, string $term): void
    {
        // An e-mail that is not published must not be discoverable through search either.
        $columns = config('providers.legacy_api.expose_email') ? [...self::FULL_SEARCH, 'providers.email'] : self::FULL_SEARCH;
        Like::anyOf($q, $columns, $term);
    }

    /**
     * Distinct non-empty values for the `?fields=` projection used by the website dropdowns.
     *
     * @return list<array<string, string>>
     */
    public function distinct(string $field, string $locale): array
    {
        if ($field === 'type') {
            $types = ProviderType::query()->with('translations')
                ->whereExists(function ($q) use ($locale) {
                    $q->selectRaw('1')->from('providers as p')
                        ->join('provider_translations as pt', function ($j) use ($locale) {
                            $j->on('pt.provider_id', '=', 'p.id')->where('pt.locale', '=', $locale);
                        })
                        ->whereColumn('p.provider_type_id', 'provider_types.id')
                        ->where('p.is_active', true)->whereNull('p.deleted_at');
                })
                ->orderBy('sort_order')->orderBy('id')->get();

            return $types->map(fn (ProviderType $t) => ['type' => $t->label($locale)])
                ->filter(fn (array $r) => trim($r['type']) !== '')
                ->unique('type')->values()->all();
        }

        $column = $field === 'district' ? 't.district' : 't.city';
        $values = DB::table('provider_translations as t')
            ->join('providers', 'providers.id', '=', 't.provider_id')
            ->where('t.locale', $locale)->where('providers.is_active', true)->whereNull('providers.deleted_at')
            ->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->pluck($column);

        return $values->map(fn ($v) => trim((string) $v))->filter()->unique()->values()
            ->map(fn (string $v) => [$field => $v])->all();
    }

    /** @return array<string, mixed> one V1 row */
    public function present(Provider $p, string $locale): array
    {
        $type = $p->type;
        $row = [
            'id' => $this->publicId($p),
            'name' => $p->getAttribute('t_name'),
            'type' => $type?->label($locale),
            'address' => $p->getAttribute('t_address'),
            'city' => $p->getAttribute('t_city'),
            'district' => $p->getAttribute('t_district'),
            'discount_pct' => $p->getAttribute('t_discount'),
            'hours' => $p->getAttribute('t_hours'),
            'phone' => $p->phone,
            // Not public unless explicitly approved (LEGACY_API_EXPOSE_EMAIL): the key stays so the V1 schema is unchanged.
            'email' => config('providers.legacy_api.expose_email') ? $p->email : null,
            'website' => SafeHttpUrl::passes($p->website, allowBare: true) ? $p->website : null,
            'map_url' => SafeHttpUrl::passes($p->map_url, allowBare: true) ? $p->map_url : null,
            'logo_path' => $this->logoUrl($p),
            'logo_url' => $this->logoUrl($p),
            'specialization' => $p->getAttribute('t_specialization'),
            'package' => $p->getAttribute('t_package'),
        ];
        if ($locale === 'en') {
            $row['is_translated'] = (int) ((int) $p->getAttribute('other_locales') > 0);
        }
        $row['created_at'] = $p->created_at?->toJSON();
        $row['updated_at'] = $p->updated_at?->toJSON();
        $row['latitude'] = $p->latitude === null ? null : (float) $p->latitude;
        $row['longitude'] = $p->longitude === null ? null : (float) $p->longitude;

        return $row;
    }

    public function publicId(Provider $p): int
    {
        return $p->getAttribute('legacy_lid') !== null ? (int) $p->getAttribute('legacy_lid') : self::offset() + (int) $p->id;
    }

    /** Resolve a public id (legacy id, or offset + V2 id) to a presentable provider, or null. */
    public function find(string $locale, int $publicId): ?Provider
    {
        if ($publicId >= self::offset()) {
            $v2 = $publicId - self::offset();
            $q = $this->query($locale)->where('providers.id', $v2)
                ->whereNotExists(function ($s) use ($locale) {
                    $s->selectRaw('1')->from('legacy_id_maps as m')
                        ->whereColumn('m.provider_id', 'providers.id')->where('m.source_table', self::SOURCE_TABLE[$locale]);
                });
        } else {
            $q = $this->query($locale)->whereIn('providers.id', function ($s) use ($locale, $publicId) {
                $s->select('m.provider_id')->from('legacy_id_maps as m')
                    ->where('m.source_table', self::SOURCE_TABLE[$locale])->where('m.source_id', $publicId)
                    ->whereNotNull('m.provider_id');
            });
        }

        return $q->first();
    }

    /** Absolute URL of the image V2 shows for this provider: its photo, else its type icon. Never a storage path. */
    private function logoUrl(Provider $p): ?string
    {
        if ($this->photos->exists($p->photo_path)) {
            return url('/api/legacy-media/providers/'.$p->id.'/photo');
        }
        if ($p->type !== null && $this->icons->exists($p->type->icon_path)) {
            return url('/api/legacy-media/type-icons/'.$p->type->id);
        }
        // A legacy reference that is itself an absolute http(s) link is kept, as V1 did.
        $raw = trim((string) $p->logo_path);

        return $raw !== '' && SafeHttpUrl::passes($raw, allowBare: false) ? $raw : null;
    }

    /**
     * Bilingual row of the "top providers" API.
     *
     * @return list<array<string, mixed>>
     */
    public function topProviders(): array
    {
        $featured = FeaturedProvider::query()->with('type.translations')->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->get();
        $ids = $this->featuredIds($featured);

        return $featured->map(fn (FeaturedProvider $f) => [
            'id' => $ids[$f->id],
            'name_english' => $f->name_en,
            'name_arabic' => $f->name_ar,
            'type_en' => $f->type?->key,
            'type_ar' => $f->type?->translations->firstWhere('locale', 'ar')?->name,
            'logo_url' => $this->featuredLogos->exists($f->logo_path) ? url('/api/legacy-media/featured/'.$f->id.'/logo') : null,
            'created_at' => $f->created_at?->toJSON(),
            'updated_at' => $f->updated_at?->toJSON(),
        ])->all();
    }

    /**
     * @param  Collection<int, FeaturedProvider>  $featured
     * @return array<int, int> V2 id => id the old system issued (legacy_id_maps), else offset + V2 id
     */
    private function featuredIds(Collection $featured): array
    {
        $map = DB::table('legacy_id_maps')->where('source_table', 'top_providers')
            ->whereIn('featured_provider_id', $featured->pluck('id'))
            ->selectRaw('featured_provider_id, min(source_id) as sid')->groupBy('featured_provider_id')
            ->pluck('sid', 'featured_provider_id');

        return $featured->mapWithKeys(fn (FeaturedProvider $f) => [
            $f->id => $map->has($f->id) ? (int) $map[$f->id] : self::offset() + $f->id,
        ])->all();
    }

    /** @return array{distribution: list<array<string, mixed>>, total_types: int, total_providers: int} */
    public function distribution(): array
    {
        $rows = [];
        $types = ProviderType::query()->orderBy('sort_order')->orderBy('id')->get();
        foreach ($types as $type) {
            $count = fn (string $locale) => (int) DB::table('providers as p')
                ->join('provider_translations as t', function ($j) use ($locale) {
                    $j->on('t.provider_id', '=', 'p.id')->where('t.locale', '=', $locale);
                })
                ->where('p.provider_type_id', $type->id)->where('p.is_active', true)->whereNull('p.deleted_at')->count();
            $en = $count('en');
            $ar = $count('ar');
            if ($en + $ar === 0) {
                continue;
            }
            $rows[] = [
                'type' => $type->key,
                'display_name' => LegacyTypeStyle::displayName($type->key),
                'english_count' => $en,
                'arabic_count' => $ar,
                'max_count' => max($en, $ar),
                'color' => LegacyTypeStyle::color($type->key),
                'icon' => LegacyTypeStyle::icon($type->key),
            ];
        }
        usort($rows, fn (array $a, array $b) => $b['max_count'] <=> $a['max_count']);

        return [
            'distribution' => $rows,
            'total_types' => count($rows),
            'total_providers' => array_sum(array_column($rows, 'max_count')),
        ];
    }

    /** @param  array<string, mixed>  $in */
    private function filled(array $in, string $key): bool
    {
        return isset($in[$key]) && is_string($in[$key]) && trim($in[$key]) !== '';
    }

    private function str(mixed $value): string
    {
        return trim((string) $value);
    }
}
