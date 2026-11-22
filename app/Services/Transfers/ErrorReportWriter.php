<?php

namespace App\Services\Transfers;

use App\Models\ImportJob;
use App\Models\ImportRow;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Streams the per-row error report (.xlsx) for a validation_failed job to the private disk.
 * Every string cell is written through Cells::text(), i.e. formula-injection-safe.
 */
class ErrorReportWriter
{
    public const HEADER = ['row_number', 'provider_id', 'locale', 'field', 'code', 'message_en', 'message_ar'];

    /** @return string|null relative path of the report, null when there is nothing to report */
    public function write(ImportJob $job): ?string
    {
        $summary = (array) $job->error_summary;
        $fileErrors = (array) ($summary['file_errors'] ?? []);
        if ($fileErrors === [] && $job->error_count === 0) {
            return null;
        }

        $disk = (string) config('providers.imports.disk');
        $limit = (int) config('providers.imports.error_report_limit');
        $relative = PrivateFiles::newPath(config('providers.imports.directory').'/reports');
        $absolute = PrivateFiles::absolute($disk, $relative, ensureDirectory: true);

        $writer = new Writer(new Options);
        $writer->openToFile($absolute);

        try {
            $bold = new Style(fontBold: true);
            $writer->addRow(new Row(array_map(
                fn (string $h) => new StringCell($h, $bold),
                self::HEADER
            )));

            $written = 0;
            foreach ($fileErrors as $error) {
                $writer->addRow(new Row([
                    Cells::text(null), Cells::text(null), Cells::text(null), Cells::text(null),
                    Cells::text((string) $error['code']),
                    Cells::text(ErrorMessages::file($error, 'en')),
                    Cells::text(ErrorMessages::file($error, 'ar')),
                ]));
                $written++;
            }

            $truncated = false;
            $rows = ImportRow::query()
                ->where('import_job_id', $job->id)
                ->where('action', ImportRow::ACTION_ERROR)
                ->orderBy('id')
                ->lazyById(500);

            foreach ($rows as $row) {
                $payload = (array) $row->payload;
                foreach ((array) $row->errors as $error) {
                    if ($written >= $limit) {
                        $truncated = true;
                        break 2;
                    }
                    $writer->addRow(new Row([
                        Cells::number($row->row_number),
                        isset($payload['provider_id'])
                            ? Cells::number((int) $payload['provider_id']) : Cells::text(null),
                        Cells::text(isset($payload['locale']) ? (string) $payload['locale'] : null),
                        Cells::text($error['field'] ?? null),
                        Cells::text((string) $error['code']),
                        Cells::text(ErrorMessages::row($error, 'en')),
                        Cells::text(ErrorMessages::row($error, 'ar')),
                    ]));
                    $written++;
                }
            }

            if ($truncated) {
                $writer->addRow(new Row([
                    Cells::text(null), Cells::text(null), Cells::text(null), Cells::text(null),
                    Cells::text('report_truncated'),
                    Cells::text(__('transfers.report_truncated', ['limit' => $limit], 'en')),
                    Cells::text(__('transfers.report_truncated', ['limit' => $limit], 'ar')),
                ]));
            }
        } finally {
            $writer->close();
        }

        return $relative;
    }
}
