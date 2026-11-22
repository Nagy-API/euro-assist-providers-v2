<?php

namespace App\Console\Commands;

use App\Services\Legacy\LegacyStager;

class LegacyStageCommand extends LegacyCommand
{
    protected $signature = 'legacy:stage
        {--snapshot-sha256= : SHA-256 of the frozen SQL snapshot (must equal the recorded value)}
        {--batch= : Existing staged batch (uuid or id) to re-stage; default: reuse/create the open batch}
        {--dry-run : Compute counts only; write nothing}';

    protected $description = 'Copy raw legacy source rows into the local staging table (read-only against the audit DB).';

    public function handle(LegacyStager $stager): int
    {
        return $this->guarded(function () use ($stager) {
            $result = $stager->stage(
                (string) $this->option('snapshot-sha256'),
                $this->option('batch') ?: null,
                (bool) $this->option('dry-run'),
            );

            $rows = [];
            foreach ($result['tables'] as $table => $c) {
                $rows[] = [$table, $c['source'], $c['inserted'], $c['unchanged'], $c['changed']];
            }
            $this->table(['Source table', 'Source rows', 'Inserted', 'Unchanged', 'Changed'], $rows);
            $this->info($result['dry_run'] ? 'Dry run: nothing was written.' : 'Staged into batch '.$result['batch']?->uuid.'.');

            return self::SUCCESS;
        });
    }
}
