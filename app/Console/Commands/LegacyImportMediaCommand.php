<?php

namespace App\Console\Commands;

use App\Services\Legacy\LegacyMediaImporter;

class LegacyImportMediaCommand extends LegacyCommand
{
    protected $signature = 'legacy:import-media
        {archive : Folder of the extracted frozen legacy archive (the one that contains uploads/logos)}
        {--batch= : Batch uuid or id (default: the latest active batch)}
        {--actor= : E-mail of the active Admin account (required unless --dry-run)}
        {--report= : Write per-reference outcomes (source ids, file hashes, codes) to this JSON file outside the repository}
        {--dry-run : Inventory and validate only; copy nothing}';

    protected $description = 'Copy verified legacy provider/featured images and the V1 type icons into private V2 media (ADR-018). Never fetches URLs.';

    public function handle(LegacyMediaImporter $importer): int
    {
        return $this->guarded(function () use ($importer) {
            $report = (string) $this->option('report');
            $this->assertReportPath($report);
            $dryRun = (bool) $this->option('dry-run');
            $actor = $this->resolveAdmin($this->option('actor'));

            $result = $importer->run($this->resolveBatch(), (string) $this->argument('archive'), $actor, $dryRun);

            $this->printCounts('Legacy media ('.$result['files'].' files in uploads/logos)', $result['counts']);
            if ($report !== '') {
                file_put_contents($report, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                $this->line('Report written.');
            }
            $this->info($dryRun ? 'Dry run: nothing was copied.' : 'Done. Missing or invalid files were reported, never replaced.');

            return self::SUCCESS;
        });
    }
}
