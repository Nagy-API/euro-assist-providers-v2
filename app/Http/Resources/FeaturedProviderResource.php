<?php

namespace App\Http\Resources;

use App\Http\Requests\Api\ApiRequest;
use App\Models\FeaturedProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Brand-level featured entry. The logo is intentionally NOT exposed: no authenticated or signed
 * media route exists yet and storage paths must never be returned.
 *
 * @property FeaturedProvider $resource
 */
class FeaturedProviderResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $lang = (string) $request->attributes->get(ApiRequest::LANG_ATTRIBUTE, config('providers.default_locale'));
        $f = $this->resource;

        return [
            'id' => $f->id,
            'name' => $f->name($lang),
            'name_en' => $f->name_en,
            'name_ar' => $f->name_ar,
            'type' => $f->type ? ['key' => $f->type->key, 'label' => $f->type->label($lang)] : null,
            'sort_order' => $f->sort_order,
        ];
    }
}
