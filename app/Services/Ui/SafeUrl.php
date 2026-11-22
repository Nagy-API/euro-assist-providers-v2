<?php

namespace App\Services\Ui;

/**
 * Turns stored free-text URLs into links that are safe to render as href attributes.
 * Only http and https are ever linked; javascript:, data:, vbscript:, file: etc. are never links.
 */
final class SafeUrl
{
    /** Returns a normalized http(s) URL, or null when the value must not be rendered as a link. */
    public static function http(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > 2048 || preg_match('/[\x00-\x20\x7F<>"`\\\\]/u', $value) === 1) { // apostrophe allowed (RFC 3986; output is escaped)
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false) {
            return null;
        }

        if (isset($parts['scheme'])) {
            return in_array(strtolower($parts['scheme']), ['http', 'https'], true) && ! empty($parts['host'])
                ? $value
                : null;
        }

        return null;
    }

    /**
     * A bare website such as "www.example.com/path" (no scheme) is linked as https only when it
     * looks like a host name; anything else stays plain text.
     */
    public static function website(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (self::http($value) !== null) {
            return $value;
        }
        if (! str_contains($value, ':') && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+(\/\S*)?$/i', $value) === 1) {
            return self::http('https://'.$value);
        }

        return null;
    }
}
