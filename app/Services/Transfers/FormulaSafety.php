<?php

namespace App\Services\Transfers;

/**
 * Spreadsheet formula-injection protection with a lossless round trip.
 *
 * Export: a string that a spreadsheet could interpret as a formula (starts with =, +, -, @, a tab,
 * CR or LF, also after leading whitespace) is written with ONE leading apostrophe, and always as an
 * explicit text cell. Import: exactly one leading apostrophe is removed again, but only when the
 * remainder is itself "dangerous" (so it can only have been added by escape()). A value that
 * legitimately starts with an apostrophe (e.g. "'quoted") is left untouched in both directions, and
 * a value like "'=x" is escaped to "''=x" so it also survives unchanged.
 *
 * Invariant (unit-tested): unescape(escape($v)) === $v for every string $v.
 */
final class FormulaSafety
{
    /** Dangerous start, optionally preceded by apostrophes added by (nested) escaping. */
    private const DANGEROUS = '/^\'*(?:[\t\r\n]|\s*[=+\-@])/u';

    public static function isDangerous(string $value): bool
    {
        return preg_match(self::DANGEROUS, $value) === 1;
    }

    public static function escape(string $value): string
    {
        return self::isDangerous($value) ? "'".$value : $value;
    }

    public static function unescape(string $value): string
    {
        if ($value !== '' && $value[0] === "'" && self::isDangerous(substr($value, 1))) {
            return substr($value, 1);
        }

        return $value;
    }
}
