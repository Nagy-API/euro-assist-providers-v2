<?php

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The V2 success envelope: { "data": ..., "meta": {...}, "links": {...} } on every response.
 * Pagination meta/links are built from an explicit allow-list, never from Laravel's default
 * paginator serialization (which carries internal paths and page URL arrays).
 */
final class ApiResponse
{
    public const VERSION = 'v2';

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public static function item(array $data, string $selfUrl, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => ['api_version' => self::VERSION] + $meta,
            'links' => ['self' => $selfUrl],
        ]);
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  list<mixed>  $items  already-resolved resource arrays
     * @param  array<string, mixed>  $meta  extra meta entries
     */
    public static function paginated(LengthAwarePaginator $paginator, array $items, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => $items,
            'meta' => ['api_version' => self::VERSION] + [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ] + $meta,
            'links' => [
                'self' => $paginator->url($paginator->currentPage()),
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }
}
