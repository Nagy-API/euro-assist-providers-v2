<?php

namespace App\Console\Commands;

use App\Services\Legacy\LegacyApplier;

class LegacyApplyCommand extends LegacyCommand
{
    protected $signature = 'legacy:apply
        {--batch= : Batch uuid or id (default: the latest active batch)}
        {--actor= : E-mail of an active Admin account the writes are attributed to (required unless --dry-run)}
        {--accept-provisional-types : Also use PROVISIONAL type mappings (deterministic plural/synonym labels, owner D3 approval pending); affected rows are tagged provisional_type}
        {--dry-run : Classify rows and report counts; write nothing}';

    protected $description = 'Create unpaired canonical providers/featured entries from staged rows; unmapped rows go to review.';

    public function handle(LegacyApplier $applier): int
    {
        return $this->guarded(function () use ($applier) {
            $batch = $this->resolveBatch();
            $result = $applier->apply($batch, $this->resolveAdmin($this->option('actor')), (bool) $this->option('dry-run'), (bool) $this->option('accept-provisional-types'));

            $rows = [];
            foreach ($result['states'] as $table => $states) {
                foreach ($states as $state => $count) {
                    $rows[] = [$table, $state, $count];
                }
            }
            $this->table(['Source table', 'State', 'Rows'], $rows);
            if ($result['reasons'] !== []) {
                $this->printCounts('Reason codes', $result['reasons']);
            }
            $this->info($result['dry_run'] ? 'Dry run: nothing was written.' : 'Applied.');

            return self::SUCCESS;
        });
    }
}
