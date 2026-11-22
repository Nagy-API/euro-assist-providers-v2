<?php

namespace App\Services\Transfers;

use App\Models\ProviderTranslation;

/**
 * Import/export template v1 (docs/04_IMPORT_EXPORT_SPEC.md). One worksheet; columns are matched
 * BY NAME (case-insensitive, trimmed) and never by position. One row = one (provider, locale).
 */
final class TemplateSchema
{
    public const VERSION = '1';

    /** Language-independent columns that may be written to the provider row. */
    public const SCALAR_FIELDS = ['phone', 'email', 'website', 'map_url', 'latitude', 'longitude'];

    /** Header of the template, in export order. */
    public const COLUMNS = [
        'schema_version', 'provider_id', 'locale', 'provider_type',
        'phone', 'email', 'website', 'map_url', 'latitude', 'longitude', 'is_active',
        'name', 'address', 'city', 'district', 'specialization', 'hours', 'discount_text', 'package_text',
    ];

    /** Columns every file must contain regardless of mode. */
    public const ALWAYS_REQUIRED = ['schema_version', 'locale'];

    /** @return list<string> */
    public static function translationFields(): array
    {
        return ProviderTranslation::TEXT_FIELDS;
    }

    /** Fields stored as free text where leading zeros / separators must never be altered. */
    public static function isTextual(string $field): bool
    {
        return ! in_array($field, ['provider_id', 'latitude', 'longitude', 'is_active'], true);
    }
}
