<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Only http(s) links may be stored: `javascript:`/`data:`/`file:` values would become stored XSS
 * wherever a consumer (admin UI, /api/v2 clients) renders them as hrefs. With $allowBare a bare host
 * such as "www.example.com/path" (no scheme) is accepted and kept as typed.
 *
 * Shared by the web form (ProviderService) and the spreadsheet import (RowRules) so both entry
 * points enforce exactly the same contract.
 */
final class SafeHttpUrl implements ValidationRule
{
    public function __construct(private readonly bool $allowBare = false) {}

    public static function passes(mixed $value, bool $allowBare): bool
    {
        if (! is_string($value)) {
            return false;
        }
        if (preg_match('/[\x00-\x20\x7F<>"\'`\\\\]/u', $value) === 1) {
            return false;
        }
        if (preg_match('#^https?://[^/\s]+#i', $value) === 1) {
            return true;
        }

        // A bare host must not carry any scheme ("javascript:", "data:"...); "host:8080/path" is fine.
        return $allowBare
            && preg_match('#^[a-z][a-z0-9+.\-]*:(?!\d+(/|$))#i', $value) !== 1
            && preg_match('#^[^\s/:]+\.[^\s/:]+#u', $value) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::passes($value, $this->allowBare)) {
            $fail("The $attribute must be an http or https address.");
        }
    }
}
