<?php

namespace Database\Seeders;

use App\Services\Legacy\TypeMappingService;
use Illuminate\Database\Seeder;

/**
 * Seeds legacy_type_mappings for the configured mapping version (default v1) from the 30-type
 * vocabulary. Run after ProviderTypeSeeder. Idempotent; never overwrites reviewed rows.
 */
class LegacyTypeMappingSeeder extends Seeder
{
    public function run(): void
    {
        app(TypeMappingService::class)->seedVocabulary((string) config('providers.legacy.mapping_version'));
    }
}
