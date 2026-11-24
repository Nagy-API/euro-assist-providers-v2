<?php

namespace App\Http\Controllers\Api\Legacy;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Services\LegacyApi\LegacyProviderReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * V1-compatible, public, READ-ONLY provider endpoints (ADR-021).
 * `providers` serve the English rows and `arabic-providers` the Arabic rows; the language is fixed by
 * the route default, never by client input. No write verb is registered for any of these paths.
 */
class LegacyProviderController extends Controller
{
    public function __construct(private readonly LegacyProviderReader $reader) {}

    public function index(Request $request): JsonResponse
    {
        $locale = $this->locale($request);

        // Dropdown projection used by the public website: ?fields=city | type | district.
        $fields = $request->query('fields');
        if (is_string($fields) && in_array($fields, LegacyProviderReader::FIELD_PROJECTIONS, true)) {
            return $this->json($this->reader->distinct($fields, $locale));
        }

        $query = $this->reader->query($locale);
        $this->reader->applyListFilters($query, $request->query());

        $paginate = $request->filled('page') || $request->filled('per_page') || $request->boolean('paginate', false);
        if (! $paginate) {
            return $this->json($query->get()->map(fn (Provider $p) => $this->reader->present($p, $locale))->all());
        }

        return $this->paginated($query, $this->perPage($request), $locale);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $locale = $this->locale($request);
        $provider = $this->reader->find($locale, $id);
        abort_if($provider === null, 404);

        return $this->json($this->reader->present($provider, $locale));
    }

    /** V1 `search`: `query` (or `q`) is required; broad search across the listed columns; always paginated. */
    public function search(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $term = trim((string) ($request->query('query', $request->query('q', ''))));
        if ($term === '') {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => ['query' => ['The query parameter is required.']],
            ], 422);
        }

        $query = $this->reader->query($locale);
        $this->reader->applyColumnFilters($query, $request->query());
        $this->reader->applyFullSearch($query, mb_substr($term, 0, 200));

        return $this->paginated($query, $this->perPage($request), $locale);
    }

    public function distinctNames(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $names = DB::table('provider_translations as t')
            ->join('providers', 'providers.id', '=', 't.provider_id')
            ->where('t.locale', $locale)->where('providers.is_active', true)->whereNull('providers.deleted_at')
            ->distinct()->orderBy('t.name')->pluck('t.name');

        return $this->json(['names' => $names->values()->all()]);
    }

    public function allLatLng(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $rows = $this->reader->query($locale)->get()->map(fn (Provider $p) => [
            'name' => $p->getAttribute('t_name'),
            'type' => $p->type?->label($locale),
            'latitude' => $p->latitude === null ? null : (float) $p->latitude,
            'longitude' => $p->longitude === null ? null : (float) $p->longitude,
        ])->all();

        return $this->json(['data' => $rows]);
    }

    public function distribution(): JsonResponse
    {
        return $this->json($this->reader->distribution());
    }

    public function top(): JsonResponse
    {
        return $this->json(['success' => true, 'data' => $this->reader->topProviders()]);
    }

    private function locale(Request $request): string
    {
        return $request->route('locale') === 'ar' ? 'ar' : 'en';
    }

    private function perPage(Request $request): int
    {
        $n = (int) $request->query('per_page', 25);
        $n = $n > 0 ? $n : 25;

        return min($n, (int) config('providers.legacy_api.max_per_page', 1000));
    }

    /** @param  Builder<Provider>  $query */
    private function paginated(Builder $query, int $perPage, string $locale): JsonResponse
    {
        $page = $query->paginate($perPage)->withQueryString();

        return $this->json([
            'data' => $page->getCollection()->map(fn (Provider $p) => $this->reader->present($p, $locale))->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
            'links' => [
                'self' => $page->url($page->currentPage()),
                'next' => $page->nextPageUrl(),
                'prev' => $page->previousPageUrl(),
            ],
        ]);
    }

    /** @param  array<array-key, mixed>  $payload */
    private function json(array $payload): JsonResponse
    {
        $seconds = max(0, (int) config('providers.legacy_api.cache_seconds', 60));

        return response()->json($payload)->header('Cache-Control', $seconds > 0 ? 'public, max-age='.$seconds : 'no-cache, private');
    }
}
