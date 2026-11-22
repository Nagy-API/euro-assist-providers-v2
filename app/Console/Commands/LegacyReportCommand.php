<?php

namespace App\Console\Commands;

use App\Services\Legacy\LegacyReportWriter;

class LegacyReportCommand extends LegacyCommand
{
    protected $signature = 'legacy:report
        {--batch= : Batch uuid or id (default: the latest active batch)}
        {--name= : Report file name without extension (default reconciliation-<sha8>-<version>[-rehearsal])}';

    protected $description = 'Write the sanitized, aggregate-only migration report to docs/legacy-reports/.';

    public function handle(LegacyReportWriter $writer): int
    {
        return $this->guarded(function () use ($writer) {
            $path = $writer->write($this->resolveBatch(), null, $this->option('name') ?: null);
            $this->info('Report written: '.basename($path));

            return self::SUCCESS;
        });
    }
}
