<?php

namespace App\Console\Commands;

use App\Services\Legacy\LegacyException;
use App\Services\Legacy\ReviewManifestApplier;

class LegacyApplyReviewManifestCommand extends LegacyCommand
{
    protected $signature = 'legacy:apply-review-manifest
        {manifest : Path of the owner-reviewed decision manifest (private file, outside the repository)}
        {--batch= : Batch uuid or id (default: the latest active batch)}
        {--actor= : E-mail of the active Admin account the decisions are recorded under (required unless --dry-run)}
        {--expect-sha256= : Refuse unless the manifest file has exactly this SHA-256}
        {--report= : Write the per-decision outcome (source ids and reason codes only) to this JSON file outside the repository}
        {--dry-run : Verify everything and report; write nothing}';

    protected $description = 'Re-verify an owner-reviewed migration manifest against V2 and apply only the decisions that pass (ADR-017).';

    public function handle(ReviewManifestApplier $applier): int
    {
        return $this->guarded(function () use ($applier) {
            $path = (string) $this->argument('manifest');
            $expected = (string) $this->option('expect-sha256');
            $report = (string) $this->option('report');
            $this->assertReportPath($report);

            $dryRun = (bool) $this->option('dry-run');
            $actor = $this->resolveAdmin($this->option('actor'));
            if (! $dryRun && $actor === null) {
                throw new LegacyException('Applying a review manifest requires --actor=<e-mail of an active Admin account>.');
            }

            $result = $applier->run($this->resolveBatch(), $path, $actor, $dryRun, $expected);

            $this->printCounts('Review manifest '.substr($result['manifest_sha256'], 0, 12), $result['counts'] + ['suggestions superseded' => $result['superseded_suggestions']]);
            if ($result['hold_reasons'] !== []) {
                $this->printCounts('Pairs left unchanged, by reason (a pair can have several)', $result['hold_reasons']);
            }
            if ($report !== '') {
                file_put_contents($report, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                $this->line('Report written.');
            }
            $this->info($dryRun ? 'Dry run: nothing was written.' : 'Applied. Everything that failed a check was left unchanged.');

            return self::SUCCESS;
        });
    }
}
