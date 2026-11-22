<?php

namespace App\Console\Commands;

use App\Services\Legacy\LegacyReconciler;
use App\Services\Legacy\LegacyReportWriter;

class LegacyReconcileCommand extends LegacyCommand
{
    protected $signature = 'legacy:reconcile
        {--batch= : Batch uuid or id (default: the latest active batch)}
        {--expect-baseline : Also require the live source counts to equal the recorded baseline}
        {--report : Write the aggregate-only report to docs/legacy-reports/}';

    protected $description = 'Verify every source row ended in exactly one state and totals equal the source. Non-zero exit on any discrepancy.';

    public function handle(LegacyReconciler $reconciler, LegacyReportWriter $writer): int
    {
        return $this->guarded(function () use ($reconciler, $writer) {
            $batch = $this->resolveBatch();
            $result = $reconciler->reconcile($batch, (bool) $this->option('expect-baseline'));

            $rows = [];
            foreach ($result['source'] as $table => $count) {
                $s = $result['states'][$table] ?? [];
                $rows[] = [$table, $count, $result['staged'][$table] ?? 0, $s['mapped'] ?? 0, $s['needs_review'] ?? 0, $s['rejected'] ?? 0, $s['staged'] ?? 0];
            }
            $this->table(['Source table', 'Source', 'Staged', 'mapped', 'needs_review', 'rejected', 'unprocessed'], $rows);

            if ($this->option('report')) {
                $path = $writer->write($batch, $result);
                $this->line('Report written: '.basename($path));
            }

            if (! $result['ok']) {
                foreach ($result['discrepancies'] as $code) {
                    $this->error('Discrepancy: '.$code);
                }

                return self::FAILURE;
            }

            $this->info('Reconciliation OK: every source row is accounted for.');

            return self::SUCCESS;
        });
    }
}
