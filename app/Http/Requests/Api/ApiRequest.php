<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for /api/v2 query validation. Authorization is enforced by the ApiAuthenticate /
 * ApiAuthorize middleware before a request reaches this class; validation failures are rendered
 * as the 422 error envelope.
 */
abstract class ApiRequest extends FormRequest
{
    /** Request attribute under which the resolved response locale is shared with resources. */
    public const LANG_ATTRIBUTE = 'api.lang';

    public function authorize(): bool
    {
        return true;
    }

    /** The requested response locale (ar|en), default per configuration. */
    public function lang(): string
    {
        $lang = (string) ($this->query('lang') ?: config('providers.default_locale'));
        $this->attributes->set(self::LANG_ATTRIBUTE, $lang);

        return $lang;
    }

    /** @return list<string> */
    protected function locales(): array
    {
        return array_values((array) config('providers.locales'));
    }

    protected function maxPerPage(): int
    {
        return (int) config('providers.api.max_per_page', 100);
    }

    protected function defaultPerPage(): int
    {
        return min((int) config('providers.api.default_per_page', 25), $this->maxPerPage());
    }

    /** Page size, already validated against the configured maximum (422 above it). */
    public function perPage(): int
    {
        return $this->query('per_page') !== null ? (int) $this->query('per_page') : $this->defaultPerPage();
    }
}
