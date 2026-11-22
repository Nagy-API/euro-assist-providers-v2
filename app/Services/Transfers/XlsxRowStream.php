<?php

namespace App\Services\Transfers;

use Exception;
use Generator;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Incremental (row-by-row) reader for the first worksheet. Never loads the sheet into memory:
 * only the current row is held. Keys are the REAL sheet row numbers (header = 1), because
 * empty rows are preserved by the iterator so that messages can say "row 17" exactly as Excel does.
 */
class XlsxRowStream
{
    /** Number of worksheets after the first one (they are ignored; reported as a warning). */
    public int $ignoredSheets = 0;

    /**
     * @return Generator<int, list<Cell>>
     *
     * @throws ImportRejected for unreadable/corrupt workbooks or sheets that are absurdly sparse
     */
    public function rows(string $path, int $maxSheetRows): Generator
    {
        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
        $sheetNumber = 0;

        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetNumber++;
                if ($sheetNumber > 1) {
                    $this->ignoredSheets++;

                    continue;
                }
                foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                    if ($rowIndex > $maxSheetRows) {
                        throw new ImportRejected('too_many_rows', ['max' => $maxSheetRows]);
                    }
                    yield $rowIndex => array_values($row->cells);
                }
            }
        } catch (ImportRejected $e) {
            throw $e;
        } catch (Exception $e) {
            // Corrupt XML, missing parts, bad shared strings, invalid dates, ...
            throw new ImportRejected('unreadable_file');
        } finally {
            try {
                $reader->close();
            } catch (Throwable) {
                // closing a reader that failed to open is not an error worth reporting
            }
        }

        if ($sheetNumber === 0) {
            throw new ImportRejected('unreadable_file');
        }
    }
}
