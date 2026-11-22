<?php

namespace App\Console\Commands;

use App\Models\ExportJob;
use App\Services\Transfers\PrivateFiles;
use Illuminate\Console\Command;

/** Retention for exports: deletes expired export files and marks the jobs `expired`. */
class PruneExports extends Command
{
    protected $signature = 'exports:prune';

    protected $aliases = ['exports.prune'];

    protected $description = 'Delete expired export files and mark the jobs expired.';

    public function handle(): int
    {
        $disk = (string) config('providers.exports.disk');
        $count = 0;

        ExportJob::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->whereNotIn('status', [ExportJob::STATUS_EXPIRED, ExportJob::STATUS_RUNNING])
            ->orderBy('id')
            ->chunkById(100, function ($jobs) use ($disk, &$count): void {
                foreach ($jobs as $job) {
                    PrivateFiles::delete($disk, $job->file_path);
                    ExportJob::query()->whereKey($job->id)->update([
                        'status' => ExportJob::STATUS_EXPIRED,
                        'file_path' => null,
                        'updated_at' => now(),
                    ]);
                    $count++;
                }
            });

        $this->info("Expired {$count} export jobs.");

        return self::SUCCESS;
    }
}
