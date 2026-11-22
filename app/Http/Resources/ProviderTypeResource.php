<?php

namespace App\Http\Resources;

use App\Http\Requests\Api\ApiRequest;
use App\Models\ProviderType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property ProviderType $resource */
class ProviderTypeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $lang = (string) $request->attributes->get(ApiRequest::LANG_ATTRIBUTE, config('providers.default_locale'));
        $labels = [];
        foreach ($this->resource->translations->sortBy('locale') as $translation) {
            $labels[$translation->locale] = $translation->name;
        }

        return [
            'key' => $this->resource->key,
            'label' => $this->resource->label($lang),
            'labels' => $labels === [] ? (object) [] : $labels,
            'sort_order' => $this->resource->sort_order,
        ];
    }
}
