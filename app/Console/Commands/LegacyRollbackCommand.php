<?php

namespace App\Console\Commands;

use App\Services\Legacy\BatchLocator;
use App\Services\Legacy\BatchRollback;

class LegacyRollbackCommand extends LegacyCommand
{
    protected $signature = 'legacy:rollback
        {batch : Batch uuid or id}
        {--dry-run : Report what would be deleted; delete nothing}
        {--force : Delete even providers that were edited after the migration}
        {--actor= : E-mail of the Admin account recorded in the audit event}';

    protected $description = 'Delete everything a migration batch created (single transaction) and mark it rolled back.';

    public function handle(BatchRollback $rollback): int
    {
        return $this->guarded(function () use ($rollback) {
            $result = $rollback->rollback(
                BatchLocator::find((string) $this->argument('batch')),
                (bool) $this->option('dry-run'),
                (bool) $this->option('force'),
                $this->resolveAdmin($this->option('actor')),
            );

            $this->printCounts('Rollback', array_diff_key($result, ['dry_run' => 1]));
            $this->info($result['dry_run'] ? 'Dry run: nothing was deleted.' : 'Batch rolled back.');

            return self::SUCCESS;
        });
    }
}
