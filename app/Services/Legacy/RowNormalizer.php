<?php

namespace App\Services\Legacy;

/**
 * Deterministic normalization of APPROVED fields only (docs/05_DATA_MIGRATION.md, D1/D4):
 * trim, empty -> NULL; phone, discount, hours and package stay lossless text; coordinates are
 * parsed from the original map URL with the two documented patterns and range-validated. No URL
 * is ever fetched, no phone is reformatted, no media is copied. The original map URL and the raw
 * logo string are kept as-is.
 */
class RowNormalizer
{
    /** "?q=lat,lng" / "&q=lat,lng" */
    private const PATTERN_Q = '/[?&]q=(-?\d{1,3}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)/';

    /** "@lat,lng," */
    private const PATTERN_AT = '/@(-?\d{1,3}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?),/';

    public const COORD_PARSED = 'parsed';

    public const COORD_NONE = 'none';

    public const COORD_OUT_OF_RANGE = 'out_of_range';

    /** Trim (including no-break spaces), blank -> null. Non-scalars become null. */
    public function clean(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = (string) $value;
        $trimmed = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $string);
        $trimmed = $trimmed ?? trim($string);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $raw  one source row of providers / arabic_providers
     * @return array{
     *     type_raw: ?string, phone: ?string, email: ?string, website: ?string, map_url: ?string,
     *     latitude: ?float, longitude: ?float, coordinate_status: string, logo_path: ?string,
     *     translation: array<string, ?string>
     * }
     */
    public function provider(array $raw): array
    {
        $mapUrl = $this->clean($raw['map_url'] ?? null);
        [$lat, $lng, $status] = $this->coordinates($mapUrl);

        return [
            'type_raw' => $this->clean($raw['type'] ?? null),
            'phone' => $this->clean($raw['phone'] ?? null),
            'email' => $this->clean($raw['email'] ?? null),
            'website' => $this->clean($raw['website'] ?? null),
            'map_url' => $mapUrl,
            'latitude' => $lat,
            'longitude' => $lng,
            'coordinate_status' => $status,
            'logo_path' => $this->clean($raw['logo_path'] ?? null),
            'translation' => [
                'name' => $this->clean($raw['name'] ?? null),
                'address' => $this->clean($raw['address'] ?? null),
                'city' => $this->clean($raw['city'] ?? null),
                'district' => $this->clean($raw['district'] ?? null),
                'specialization' => $this->clean($raw['specialization'] ?? null),
                'hours' => $this->clean($raw['hours'] ?? null),
                'discount_text' => $this->clean($raw['discount_pct'] ?? null),
                'package_text' => $this->clean($raw['package'] ?? null),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw  one source row of top_providers
     * @return array{name_en: ?string, name_ar: ?string, type_en_raw: ?string, type_ar_raw: ?string, logo_reference: ?string}
     */
    public function featured(array $raw): array
    {
        return [
            'name_en' => $this->clean($raw['name_english'] ?? null),
            'name_ar' => $this->clean($raw['name_arabic'] ?? null),
            'type_en_raw' => $this->clean($raw['type_en'] ?? null),
            'type_ar_raw' => $this->clean($raw['type_ar'] ?? null),
            'logo_reference' => $this->clean($raw['logo_path'] ?? null),
        ];
    }

    /**
     * @return array{0: ?float, 1: ?float, 2: string} latitude, longitude, status (parsed|none|out_of_range)
     */
    public function coordinates(?string $mapUrl): array
    {
        if ($mapUrl === null) {
            return [null, null, self::COORD_NONE];
        }

        foreach ([self::PATTERN_Q, self::PATTERN_AT] as $pattern) {
            if (preg_match($pattern, $mapUrl, $m) === 1) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];

                if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                    return [null, null, self::COORD_OUT_OF_RANGE];
                }

                return [round($lat, 7), round($lng, 7), self::COORD_PARSED];
            }
        }

        return [null, null, self::COORD_NONE];
    }

    /**
     * Comparison key for suggestion signals ONLY (never stored on a provider): digits of the
     * phone with Arabic-Indic digits mapped to ASCII; null when fewer than 6 digits remain.
     */
    public function phoneKey(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $ascii = strtr($phone, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $digits = preg_replace('/\D+/', '', $ascii) ?? '';

        return strlen($digits) >= 6 ? $digits : null;
    }
}
