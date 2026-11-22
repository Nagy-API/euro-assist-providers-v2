<?php

namespace App\Jobs;

use App\Models\ExportJob;
use App\Services\Transfers\ExportGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Streams the export workbook to private storage. Runs once; a failure is final (the user re-requests). */
class GenerateExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $exportJobId) {}

    public function handle(ExportGenerator $generator): void
    {
        $job = ExportJob::query()->find($this->exportJobId);
        if ($job === null) {
            return;
        }

        $claimed = ExportJob::query()->whereKey($job->id)->where('status', ExportJob::STATUS_QUEUED)
            ->update(['status' => ExportJob::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        if ($claimed === 0) {
            return;
        }

        $generator->generate($job->refresh());
    }

    public function failed(?Throwable $e): void
    {
        $job = ExportJob::query()->find($this->exportJobId);
        if ($job !== null) {
            app(ExportGenerator::class)->markFailed($job, $e);
        }
    }
}
