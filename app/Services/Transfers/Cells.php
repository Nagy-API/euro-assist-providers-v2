<?php

namespace App\Services\Transfers;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;

/**
 * Safe cell factories for everything we write. Strings are ALWAYS explicit text cells (never
 * Cell::fromValue(), which would turn a leading "=" into a formula) and are passed through
 * FormulaSafety::escape().
 */
final class Cells
{
    public static function text(?string $value): Cell
    {
        if ($value === null || $value === '') {
            return new EmptyCell(null);
        }

        return new StringCell(FormulaSafety::escape($value));
    }

    public static function number(int|float $value): Cell
    {
        return new NumericCell($value);
    }
}
