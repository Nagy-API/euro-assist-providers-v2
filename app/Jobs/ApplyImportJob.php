<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Services\Transfers\ImportApplier;
use App\Services\Transfers\ImportLockBusy;
use App\Services\Transfers\ImportNotApplicable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies a confirmed import. Safe to deliver twice or to resume: only pending rows are processed
 * and the global apply lock serializes concurrent runs. Lock contention releases the job back to
 * the queue (it does not count as a failure); real exceptions are limited by $maxExceptions and
 * end in failed(), which marks the job interrupted (some rows applied) or failed.
 */
class ApplyImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** 0 = unlimited releases (lock contention); real failures are bounded by $maxExceptions. */
    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 3600;

    public function __construct(public readonly int $importJobId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function handle(ImportApplier $applier): void
    {
        $job = ImportJob::query()->find($this->importJobId);
        if ($job === null) {
            return;
        }

        try {
            $applier->apply($job);
        } catch (ImportNotApplicable $e) {
            Log::warning('import.apply.rejected', ['job' => $job->uuid, 'reason' => $e->reason]);
            if ($e->failJob) {
                $applier->markFailed($job, null, $e->reason);
            }
        } catch (ImportLockBusy) {
            $this->release((int) config('providers.imports.lock_release_delay'));
        }
    }

    public function failed(?Throwable $e): void
    {
        $job = ImportJob::query()->find($this->importJobId);
        if ($job !== null) {
            app(ImportApplier::class)->markFailed($job, $e);
        }
    }
}
