<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

/** GET /api/v2/providers/coordinates: its own, larger page-size ceiling (providers.api.coordinates_max). */
class CoordinatesRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'lang' => ['nullable', 'string', Rule::in($this->locales())],
            'type' => ['nullable', 'string', 'max:64', Rule::exists('provider_types', 'key')],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.$this->coordinatesMax()],
        ];
    }

    public function coordinatesMax(): int
    {
        return max(1, (int) config('providers.api.coordinates_max', 5000));
    }

    public function perPage(): int
    {
        $default = min((int) config('providers.api.coordinates_default_per_page', 1000), $this->coordinatesMax());

        return $this->query('per_page') !== null ? (int) $this->query('per_page') : $default;
    }
}
