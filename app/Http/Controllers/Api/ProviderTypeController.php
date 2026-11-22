<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PagedRequest;
use App\Http\Resources\ApiResponse;
use App\Http\Resources\ProviderTypeResource;
use App\Models\ProviderType;
use Illuminate\Http\JsonResponse;

class ProviderTypeController extends Controller
{
    /** Active provider types in display order; a small, bounded vocabulary (hard limit 500). */
    public function index(PagedRequest $request): JsonResponse
    {
        $lang = $request->lang();

        $types = ProviderType::query()
            ->where('is_active', true)
            ->with('translations')
            ->orderBy('sort_order')->orderBy('id')
            ->limit(500)
            ->get();

        return ApiResponse::item(
            ProviderTypeResource::collection($types)->resolve($request),
            route('api.v2.provider-types.index'),
            ['lang' => $lang, 'total' => $types->count()],
        );
    }
}
