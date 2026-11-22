<?php

namespace App\Services\Transfers;

use Illuminate\Support\Facades\Lang;

/**
 * Turns stored error/warning codes (never cell values) into localized messages for the UI and
 * the error-report spreadsheet.
 */
final class ErrorMessages
{
    /** @param array{field?: string|null, code: string, params?: array<string, scalar|null>} $error */
    public static function row(array $error, ?string $locale = null): string
    {
        $code = $error['code'];
        $key = "transfers.errors.row.$code";
        if (! Lang::has($key, $locale)) {
            $key = str_starts_with($code, 'validation_') ? 'transfers.errors.row.validation_invalid' : 'transfers.errors.row.unknown';
        }

        return (string) __($key, self::params($error, $locale), $locale);
    }

    /** @param array{code: string, params?: array<string, scalar|null>} $error */
    public static function file(array $error, ?string $locale = null): string
    {
        $key = "transfers.errors.file.{$error['code']}";
        if (! Lang::has($key, $locale)) {
            $key = 'transfers.errors.file.unknown';
        }

        return (string) __($key, array_map('strval', $error['params'] ?? []), $locale);
    }

    public static function warning(string $code, ?string $locale = null): string
    {
        $key = "transfers.warnings.$code";

        return Lang::has($key, $locale) ? (string) __($key, [], $locale) : $code;
    }

    /**
     * @param  array{field?: string|null, params?: array<string, scalar|null>}  $error
     * @return array<string, string>
     */
    private static function params(array $error, ?string $locale): array
    {
        $field = $error['field'] ?? null;
        $params = array_map('strval', $error['params'] ?? []);
        $params['field'] = $field === null ? '' : (Lang::has("transfers.fields.$field", $locale) ? (string) __("transfers.fields.$field", [], $locale) : $field);

        return $params;
    }
}
