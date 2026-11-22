<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Services\Transfers\ImportValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Parses and validates the whole uploaded workbook and stages import_rows. Never touches the
 * canonical provider tables. Idempotent: a re-run restages from scratch.
 */
class ValidateImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $importJobId) {}

    public function handle(ImportValidator $validator): void
    {
        $job = ImportJob::query()->find($this->importJobId);
        if ($job === null) {
            return;
        }

        // Only a freshly uploaded (or retried) job can start validating; anything else is a stale message.
        $claimed = ImportJob::query()->whereKey($job->id)
            ->whereIn('status', [ImportJob::STATUS_UPLOADED])
            ->update(['status' => ImportJob::STATUS_VALIDATING, 'started_at' => now(), 'attempts' => $job->attempts + 1, 'updated_at' => now()]);
        if ($claimed === 0) {
            return;
        }

        $validator->run($job->refresh());
    }

    public function failed(?Throwable $e): void
    {
        $job = ImportJob::query()->find($this->importJobId);
        if ($job === null || $job->status !== ImportJob::STATUS_VALIDATING) {
            return;
        }
        $job->forceFill([
            'status' => ImportJob::STATUS_FAILED,
            'failure_reason' => $e !== null ? mb_substr(class_basename($e), 0, 100) : 'validation_failed',
            'finished_at' => now(),
        ])->save();
        Log::error('import.validation.crashed', ['job' => $job->uuid, 'class' => $e !== null ? $e::class : null]);
    }
}
