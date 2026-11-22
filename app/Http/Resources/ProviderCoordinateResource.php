<?php

namespace App\Http\Resources;

use App\Http\Requests\Api\ApiRequest;
use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Map marker: id, display name, type key and parsed coordinates. Only providers WITH coordinates
 * are ever represented (the query guarantees it).
 *
 * @property Provider $resource
 */
class ProviderCoordinateResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $lang = (string) $request->attributes->get(ApiRequest::LANG_ATTRIBUTE, config('providers.default_locale'));
        $translation = $this->resource->displayTranslation($lang);

        return [
            'id' => $this->resource->id,
            'name' => $translation?->name,
            'locale_fallback' => $translation?->locale !== $lang,
            'type' => $this->resource->type?->key,
            'latitude' => (float) $this->resource->latitude,
            'longitude' => (float) $this->resource->longitude,
        ];
    }
}
