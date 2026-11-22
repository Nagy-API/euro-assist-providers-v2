<?php

namespace App\Services\Legacy;

use App\Models\AuditEvent;
use App\Models\FeaturedProvider;
use App\Models\LegacyIdMap;
use App\Models\LegacyMigrationBatch;
use App\Models\LegacyPairSuggestion;
use App\Models\Provider;
use App\Models\ProviderTranslation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Step 8: undo a batch by migration_batch_id (providers, translations, id maps, pair suggestions,
 * featured providers) in one transaction and mark the batch rolled_back.
 *
 * Providers that people edited after the migration (provider.updated / deleted / restored audit
 * events, or a revision above what the pipeline itself produced) are protected: the rollback
 * refuses unless $force is given, because deleting them would destroy later human work.
 */
class BatchRollback
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{providers: int, translations: int, id_maps: int, suggestions: int, featured: int, edited_providers: int, dry_run: bool}
     */
    public function rollback(LegacyMigrationBatch $batch, bool $dryRun, bool $force, ?User $actor = null): array
    {
        LegacyGuard::assertNotProduction();

        if ($batch->status === 'rolled_back') {
            throw new LegacyException('This batch is already rolled back.');
        }

        $providerIds = Provider::query()->withTrashed()->where('migration_batch_id', $batch->id)->orderBy('id')->pluck('id')->all();
        $featuredIds = LegacyIdMap::query()->where('migration_batch_id', $batch->id)
            ->whereNotNull('featured_provider_id')->pluck('featured_provider_id')->all();

        $edited = $this->editedProviders($batch, $providerIds);

        $result = [
            'providers' => count($providerIds),
            'translations' => $this->countIn(ProviderTranslation::query(), 'provider_id', $providerIds),
            'id_maps' => LegacyIdMap::query()->where('migration_batch_id', $batch->id)->count(),
            'suggestions' => LegacyPairSuggestion::query()->where('migration_batch_id', $batch->id)->count(),
            'featured' => count($featuredIds),
            'edited_providers' => count($edited),
            'dry_run' => $dryRun,
        ];

        if (count($edited) > 0 && ! $force) {
            throw new LegacyException(count($edited).' provider(s) were edited after the migration; rollback refused (use --force to delete them anyway).');
        }
        if ($dryRun) {
            return $result;
        }

        DB::transaction(function () use ($batch, $providerIds, $featuredIds) {
            LegacyPairSuggestion::query()->where('migration_batch_id', $batch->id)->delete();
            LegacyIdMap::query()->where('migration_batch_id', $batch->id)->delete();

            foreach (array_chunk($featuredIds, 400) as $chunk) {
                FeaturedProvider::query()->whereIn('id', $chunk)->delete();
            }
            foreach (array_chunk($providerIds, 400) as $chunk) {
                ProviderTranslation::query()->whereIn('provider_id', $chunk)->delete();
                Provider::query()->withTrashed()->whereIn('id', $chunk)->forceDelete();
            }

            $batch->forceFill(['status' => 'rolled_back', 'rolled_back_at' => now()])->save();
        });

        $this->audit->record('migration.batch.rolled_back', $batch, 'success', [
            'batch_uuid' => $batch->uuid,
            'forced' => $force,
        ] + array_diff_key($result, ['dry_run' => 1]), $actor);

        return $result;
    }

    /**
     * @param  list<int>  $providerIds
     * @return list<int> ids of providers with later non-migration edits
     */
    private function editedProviders(LegacyMigrationBatch $batch, array $providerIds): array
    {
        $edited = [];
        $bumps = (array) (($batch->counts ?? [])['revision_bumps'] ?? []);
        $morph = (new Provider)->getMorphClass();

        foreach (array_chunk($providerIds, 400) as $chunk) {
            foreach (Provider::query()->withTrashed()->whereIn('id', $chunk)->get(['id', 'revision']) as $provider) {
                if ($provider->revision > 1 + (int) ($bumps[(string) $provider->id] ?? 0)) {
                    $edited[$provider->id] = true;
                }
            }

            $events = AuditEvent::query()
                ->where('target_type', $morph)
                ->whereIn('target_id', array_map('strval', $chunk))
                ->whereIn('action', ['provider.updated', 'provider.deleted', 'provider.restored'])
                ->pluck('target_id');
            foreach ($events as $id) {
                $edited[(int) $id] = true;
            }
        }

        return array_keys($edited);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @param  list<int>  $ids
     */
    private function countIn($query, string $column, array $ids): int
    {
        $total = 0;
        foreach (array_chunk($ids, 400) as $chunk) {
            $total += (clone $query)->whereIn($column, $chunk)->count();
        }

        return $total;
    }
}
