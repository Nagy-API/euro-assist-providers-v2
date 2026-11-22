<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PagedRequest;
use App\Http\Resources\ApiResponse;
use App\Http\Resources\FeaturedProviderResource;
use App\Models\FeaturedProvider;
use Illuminate\Http\JsonResponse;

class FeaturedProviderController extends Controller
{
    /** Active brand-level featured entries ordered by sort_order then id. Logos are not exposed. */
    public function index(PagedRequest $request): JsonResponse
    {
        $lang = $request->lang();

        $page = FeaturedProvider::query()
            ->with('type.translations')
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return ApiResponse::paginated(
            $page,
            FeaturedProviderResource::collection($page->getCollection())->resolve($request),
            ['lang' => $lang],
        );
    }
}
