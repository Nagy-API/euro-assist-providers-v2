<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CoordinatesRequest;
use App\Http\Requests\Api\ListProvidersRequest;
use App\Http\Requests\Api\PagedRequest;
use App\Http\Resources\ApiResponse;
use App\Http\Resources\ProviderCoordinateResource;
use App\Http\Resources\ProviderDetailResource;
use App\Http\Resources\ProviderResource;
use App\Models\Provider;
use App\Models\ProviderType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only provider endpoints. Authorization (token or configured public read) is enforced by
 * the ApiAuthenticate/ApiAuthorize route middleware; this controller additionally restricts
 * inactive providers to authenticated principals.
 */
class ProviderController extends Controller
{
    private const RELATIONS = ['type.translations', 'translations'];

    public function index(ListProvidersRequest $request): JsonResponse
    {
        $lang = $request->lang();
        [$sortColumn, $direction] = $request->sort();

        $query = Provider::query()
            ->select('providers.*')
            ->with(self::RELATIONS)
            ->where('providers.is_active', $this->activeFilter($request, $request->requestedActive()))
            ->search($request->query('q'));

        if ($type = $request->query('type')) {
            $query->whereHas('type', fn (Builder $t) => $t->where('key', $type));
        }
        $query->whereTranslated('city', $this->stringOrNull($request->query('city')), $lang);
        $query->whereTranslated('district', $this->stringOrNull($request->query('district')), $lang);

        if ($sortColumn === 'name') {
            // Sort by the requested locale's name; providers without that translation come last.
            $query->leftJoin('provider_translations as sort_t', function ($join) use ($lang) {
                $join->on('sort_t.provider_id', '=', 'providers.id')->where('sort_t.locale', '=', $lang);
            })->orderByRaw('sort_t.name is null')->orderBy('sort_t.name', $direction);
        } else {
            $query->orderBy('providers.'.$sortColumn, $direction);
        }
        $query->orderBy('providers.id'); // stable tie-break

        $page = $query->paginate($request->perPage())->withQueryString();

        return ApiResponse::paginated(
            $page,
            ProviderResource::collection($page->getCollection())->resolve($request),
            ['lang' => $lang],
        );
    }

    public function show(PagedRequest $request, int $provider): JsonResponse
    {
        $lang = $request->lang();

        $model = Provider::query()->with(self::RELATIONS)->find($provider);
        if ($model === null || (! $model->is_active && $request->user() === null)) {
            throw new ModelNotFoundException;
        }

        return ApiResponse::item(
            (new ProviderDetailResource($model))->resolve($request),
            route('api.v2.providers.show', $model->id),
            ['lang' => $lang],
        );
    }

    /** Distinct names available in one locale, alphabetical, paginated. */
    public function names(PagedRequest $request): JsonResponse
    {
        $lang = $request->lang();

        $page = DB::table('provider_translations as t')
            ->join('providers as p', 'p.id', '=', 't.provider_id')
            ->whereNull('p.deleted_at')
            ->where('p.is_active', true)
            ->where('t.locale', $lang)
            ->distinct()
            ->orderBy('t.name')
            ->select('t.name')
            ->paginate($request->perPage())
            ->withQueryString();

        return ApiResponse::paginated($page, $page->getCollection()->pluck('name')->values()->all(), ['lang' => $lang]);
    }

    /** Map markers: only providers that have parsed coordinates, bounded by providers.api.coordinates_max. */
    public function coordinates(CoordinatesRequest $request): JsonResponse
    {
        $lang = $request->lang();

        $query = Provider::query()
            ->select(['providers.id', 'providers.provider_type_id', 'providers.latitude', 'providers.longitude'])
            ->with(['type:id,key', 'translations:id,provider_id,locale,name'])
            ->where('providers.is_active', true)
            ->whereNotNull('providers.latitude')
            ->whereNotNull('providers.longitude')
            ->orderBy('providers.id');

        if ($type = $request->query('type')) {
            $query->whereHas('type', fn (Builder $t) => $t->where('key', $type));
        }

        $page = $query->paginate($request->perPage())->withQueryString();

        return ApiResponse::paginated(
            $page,
            ProviderCoordinateResource::collection($page->getCollection())->resolve($request),
            ['lang' => $lang, 'max_per_page' => $request->coordinatesMax()],
        );
    }

    /**
     * Canonical (deduplicated) active providers per type. NOT the legacy "max(English, Arabic)"
     * dashboard estimate: one provider is counted once no matter how many translations it has.
     */
    public function distribution(PagedRequest $request): JsonResponse
    {
        $lang = $request->lang();

        $types = ProviderType::query()
            ->where('is_active', true)
            ->with('translations')
            ->withCount(['providers' => fn (Builder $q) => $q->where('providers.is_active', true)])
            ->orderBy('sort_order')->orderBy('id')
            ->limit(500)
            ->get();

        $data = $types->map(fn (ProviderType $t) => [
            'type' => $t->key,
            'label' => $t->label($lang),
            'providers' => (int) $t->providers_count,
        ])->all();

        return ApiResponse::item($data, route('api.v2.providers.distribution'), [
            'lang' => $lang,
            'total_providers' => array_sum(array_column($data, 'providers')),
            'definition' => 'Number of active, non-deleted canonical providers per active provider type. A provider with both Arabic and English translations counts once.',
        ]);
    }

    /** Inactive providers are visible only to authenticated principals; anonymous callers may not ask. */
    private function activeFilter(Request $request, ?bool $requested): bool
    {
        $active = $requested ?? true;
        if (! $active && $request->user() === null) {
            throw new AuthorizationException('Inactive providers require authentication.');
        }

        return $active;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
