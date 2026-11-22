<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

/** Query parameters of GET /api/v2/providers (all optional, all validated, all bounded). */
class ListProvidersRequest extends ApiRequest
{
    public const SORTS = ['id', 'name', 'updated_at'];

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $sorts = collect(self::SORTS)->flatMap(fn (string $s) => [$s, '-'.$s])->all();

        return [
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:64', Rule::exists('provider_types', 'key')],
            'city' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'lang' => ['nullable', 'string', Rule::in($this->locales())],
            'is_active' => ['nullable', 'string', Rule::in(['true', 'false', '1', '0'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.$this->maxPerPage()],
            'sort' => ['nullable', 'string', Rule::in($sorts)],
        ];
    }

    /** True/false when the caller supplied is_active, null otherwise. */
    public function requestedActive(): ?bool
    {
        $value = $this->query('is_active');

        return $value === null || $value === '' ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array{0: string, 1: 'asc'|'desc'} */
    public function sort(): array
    {
        $sort = (string) ($this->query('sort') ?: 'id');

        return str_starts_with($sort, '-') ? [substr($sort, 1), 'desc'] : [$sort, 'asc'];
    }
}
