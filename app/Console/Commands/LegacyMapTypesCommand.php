<?php

namespace App\Console\Commands;

use App\Services\Legacy\LegacySource;
use App\Services\Legacy\TypeMappingService;
use Illuminate\Support\Facades\DB;

class LegacyMapTypesCommand extends LegacyCommand
{
    protected $signature = 'legacy:map-types
        {--mapping-version= : Mapping version (default providers.legacy.mapping_version)}
        {--from-legacy : Also align the mapping with the frozen `types` table (read-only)}
        {--dry-run : Report what would be inserted, then roll back}';

    protected $description = 'Seed the versioned legacy type mapping (approved only on unambiguous evidence).';

    public function handle(TypeMappingService $service, LegacySource $source): int
    {
        return $this->guarded(function () use ($service, $source) {
            $version = (string) ($this->option('mapping-version') ?: config('providers.legacy.mapping_version'));
            $dry = (bool) $this->option('dry-run');

            DB::beginTransaction();
            try {
                $vocabulary = $service->seedVocabulary($version);
                $legacy = $this->option('from-legacy')
                    ? $service->syncFromLegacy($version, $source->all('types'))
                    : null;
                $dry ? DB::rollBack() : DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            $this->printCounts('Vocabulary ('.$version.')', $vocabulary);
            if ($legacy !== null) {
                $this->printCounts('From legacy types table', $legacy);
            }
            $this->info($dry ? 'Dry run: nothing was written.' : 'Mapping written.');

            return self::SUCCESS;
        });
    }
}
