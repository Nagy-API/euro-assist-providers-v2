<?php

namespace App\Http\Resources;

use App\Http\Requests\Api\ApiRequest;
use App\Models\Provider;
use App\Models\ProviderTranslation;
use App\Rules\SafeHttpUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of a canonical provider. EXPLICIT allow-list: new columns never appear
 * here by accident. Deliberately absent: created_by, updated_by, revision, migration_batch_id,
 * logo_path (no media route exists yet), deleted_at and every user identifier.
 *
 * Text comes from one translation: the requested `lang`, else the default locale, else any.
 * `locale` names the translation actually used and `locale_fallback` is true when that is not the
 * requested one. Text is never machine-translated or invented.
 *
 * @property Provider $resource
 */
class ProviderResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $provider = $this->resource;
        $lang = $this->lang($request);
        $translation = $provider->displayTranslation($lang);

        return [
            'id' => $provider->id,
            'type' => $provider->type ? [
                'key' => $provider->type->key,
                'label' => $provider->type->label($lang),
            ] : null,
            'locale' => $translation?->locale,
            'locale_fallback' => $translation?->locale !== $lang,
            'available_locales' => $provider->translations->pluck('locale')->sort()->values()->all(),
            'name' => $translation?->name,
            'address' => $translation?->address,
            'city' => $translation?->city,
            'district' => $translation?->district,
            'specialization' => $translation?->specialization,
            'hours' => $translation?->hours,
            'discount_text' => $translation?->discount_text,
            'package_text' => $translation?->package_text,
            'phone' => $provider->phone,
            'email' => $provider->email,
            // Defense in depth: values that are not http(s) links (e.g. preserved legacy data) are
            // never handed to API consumers, which may render them as hrefs.
            'website' => SafeHttpUrl::passes($provider->website, allowBare: true) ? $provider->website : null,
            'map_url' => SafeHttpUrl::passes($provider->map_url, allowBare: true) ? $provider->map_url : null,
            'latitude' => $provider->latitude === null ? null : (float) $provider->latitude,
            'longitude' => $provider->longitude === null ? null : (float) $provider->longitude,
            'is_active' => (bool) $provider->is_active,
            'updated_at' => $provider->updated_at?->toIso8601ZuluString(),
        ];
    }

    protected function lang(Request $request): string
    {
        return (string) $request->attributes->get(ApiRequest::LANG_ATTRIBUTE, config('providers.default_locale'));
    }

    /**
     * Every stored translation, keyed by locale (detail endpoint only).
     *
     * @return array<string, array<string, string|null>>
     */
    protected function translationsMap(): array
    {
        $map = [];
        foreach ($this->resource->translations->sortBy('locale') as $translation) {
            $map[$translation->locale] = collect(ProviderTranslation::TEXT_FIELDS)
                ->mapWithKeys(fn (string $field) => [$field => $translation->{$field}])
                ->all();
        }

        return $map;
    }
}
