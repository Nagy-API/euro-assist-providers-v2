<?php

namespace App\Console\Commands;

use App\Models\ImportJob;
use App\Models\ImportRow;
use App\Services\Transfers\PrivateFiles;
use Illuminate\Console\Command;

/**
 * Retention for imports: deletes the original upload, the error report and the staged row payloads of
 * expired jobs. The job row itself stays as history (status/counts only). Jobs that were never applied
 * become `cancelled` (reason: expired) so a purged preview can never be confirmed. Jobs that are
 * validating or applying are never touched.
 */
class PruneImports extends Command
{
    protected $signature = 'imports:prune';

    protected $aliases = ['imports.prune'];

    protected $description = 'Delete expired import files and staged import rows.';

    public function handle(): int
    {
        $disk = (string) config('providers.imports.disk');
        $jobsPruned = 0;
        $rowsDeleted = 0;

        ImportJob::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->whereNotIn('status', [ImportJob::STATUS_VALIDATING, ImportJob::STATUS_APPLYING])
            ->where(function ($q) {
                $q->where('stored_path', '!=', '')->orWhereNotNull('error_report_path')
                    ->orWhereHas('rows');
            })
            ->orderBy('id')
            ->chunkById(100, function ($jobs) use ($disk, &$jobsPruned, &$rowsDeleted): void {
                foreach ($jobs as $job) {
                    PrivateFiles::delete($disk, $job->stored_path);
                    PrivateFiles::delete($disk, $job->error_report_path);

                    do {
                        $deleted = ImportRow::query()->where('import_job_id', $job->id)->limit(2000)->delete();
                        $rowsDeleted += $deleted;
                    } while ($deleted > 0);

                    $update = ['stored_path' => '', 'error_report_path' => null];
                    if (in_array($job->status, [
                        ImportJob::STATUS_UPLOADED, ImportJob::STATUS_READY, ImportJob::STATUS_INTERRUPTED,
                        ImportJob::STATUS_CONFLICT, ImportJob::STATUS_FAILED,
                    ], true)) {
                        $update += ['status' => ImportJob::STATUS_CANCELLED, 'failure_reason' => 'expired', 'finished_at' => now()];
                    }
                    ImportJob::query()->whereKey($job->id)->update($update);
                    $jobsPruned++;
                }
            });

        $this->info("Pruned {$jobsPruned} expired import jobs ({$rowsDeleted} staged rows).");

        return self::SUCCESS;
    }
}
