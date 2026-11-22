<?php

namespace App\Services\Transfers;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\BooleanCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;

/**
 * Cell-level parsing of one data row. Every value is read as TEXT exactly as the cell holds it
 * (no numeric coercion, so leading zeros and separators in phone/hours/discount text survive);
 * numeric cells are stringified without any "repair" and, for phone, flagged with a warning.
 */
class TemplateRowParser
{
    private const CONTROL_CHARS = '/[\x00-\x08\x0B\x0C\x0E-\x1F]/';

    /**
     * @param  list<Cell>  $cells
     * @param  array<string, int>  $columns  column key => cell index
     * @return ParsedRow|null null for a completely blank row (skipped)
     */
    public function parse(int $rowNumber, array $cells, array $columns): ?ParsedRow
    {
        if ($this->isBlank($cells)) {
            return null;
        }

        $row = new ParsedRow($rowNumber);
        $read = fn (string $field): ?string => isset($columns[$field])
            ? $this->cellText($cells[$columns[$field]] ?? null, $field, $row)
            : null;

        // schema_version: the marker that also tells us exporter escaping may be present.
        $version = $read('schema_version');
        if ($version !== TemplateSchema::VERSION) {
            $row->error('schema_version', $version === null ? 'schema_version_required' : 'unsupported_schema_version');
        }

        $locale = $read('locale');
        if ($locale === null) {
            $row->error('locale', 'locale_required');
        } elseif (! in_array(strtolower($locale), config('providers.locales'), true)) {
            $row->error('locale', 'invalid_locale');
        } else {
            $row->locale = strtolower($locale);
        }

        if (isset($columns['provider_id'])) {
            $id = $read('provider_id');
            if ($id !== null) {
                if (preg_match('/^(\d{1,18})(\.0+)?$/', $id, $m) === 1 && (int) $m[1] >= 1) {
                    $row->providerId = (int) $m[1];
                } else {
                    $row->error('provider_id', 'invalid_provider_id');
                }
            }
        }

        if (isset($columns['provider_type'])) {
            $row->typeKey = $read('provider_type');
        }

        if (isset($columns['is_active'])) {
            $active = $this->boolean($cells[$columns['is_active']] ?? null, $row);
            $row->isActive = $active;
        }

        foreach ([...TemplateSchema::SCALAR_FIELDS, ...TemplateSchema::translationFields()] as $field) {
            if (! isset($columns[$field])) {
                continue;
            }
            $value = $read($field);
            if (in_array($field, ['latitude', 'longitude'], true) && $value !== null) {
                if (preg_match('/^-?\d+(\.\d+)?$/', $value) === 1) {
                    $value = number_format((float) $value, 7, '.', '');
                } else {
                    $row->error($field, 'invalid_number');
                }
            }
            $row->values[$field] = $value;
        }

        return $row;
    }

    /** @param list<Cell> $cells */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell instanceof EmptyCell) {
                continue;
            }
            $value = $cell->getValue();
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            if ($value === null) {
                continue;
            }

            return false;
        }

        return true;
    }

    /** Text of a cell, or null when blank. Adds row errors/warnings for unusable cell types. */
    private function cellText(?Cell $cell, string $field, ParsedRow $row): ?string
    {
        if ($cell === null || $cell instanceof EmptyCell) {
            return null;
        }

        if ($cell instanceof FormulaCell) {
            // A cell that is (or starts like) a formula is never trusted or evaluated.
            $row->error($field, 'formula_not_allowed');

            return null;
        }
        if ($cell instanceof ErrorCell) {
            $row->error($field, 'cell_error');

            return null;
        }

        if ($cell instanceof NumericCell) {
            $number = $cell->getValue();
            if ($field === 'phone') {
                $row->warnings[] = 'phone_numeric_cell';
            }
            $text = is_int($number) ? (string) $number : $this->floatText((float) $number);

            return $text === '' ? null : $text;
        }

        if ($cell instanceof BooleanCell) {
            $row->error($field, 'unsupported_cell_type');

            return null;
        }

        if (! $cell instanceof StringCell) {
            // dates, durations, rich text: never silently converted
            $row->error($field, 'unsupported_cell_type');

            return null;
        }

        $text = $cell->getValue();
        if (preg_match(self::CONTROL_CHARS, $text) === 1) {
            $row->error($field, 'invalid_characters');

            return null;
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = FormulaSafety::unescape($text);
        $text = trim($text);

        return $text === '' ? null : $text;
    }

    private function floatText(float $number): string
    {
        $text = (string) $number;

        return str_contains($text, 'E') ? rtrim(rtrim(number_format($number, 15, '.', ''), '0'), '.') : $text;
    }

    private function boolean(?Cell $cell, ParsedRow $row): ?bool
    {
        if ($cell === null || $cell instanceof EmptyCell) {
            return null;
        }
        if ($cell instanceof BooleanCell) {
            return (bool) $cell->getValue();
        }
        $text = $cell instanceof NumericCell ? (string) $cell->getValue() : $cell->getValue();
        if (! is_string($text)) {
            $row->error('is_active', 'invalid_boolean');

            return null;
        }
        $text = strtolower(trim($text));
        if ($text === '') {
            return null;
        }

        return match ($text) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => $this->invalidBoolean($row),
        };
    }

    private function invalidBoolean(ParsedRow $row): null
    {
        $row->error('is_active', 'invalid_boolean');

        return null;
    }
}
