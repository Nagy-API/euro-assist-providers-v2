<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

/** lang + page + per_page; used by names, featured-providers, provider-types and distribution. */
class PagedRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'lang' => ['nullable', 'string', Rule::in($this->locales())],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.$this->maxPerPage()],
        ];
    }
}
