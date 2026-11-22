<?php

namespace App\Services\Legacy;

use App\Models\LegacyIdMap;
use App\Models\LegacyMigrationBatch;
use App\Models\LegacyPairSuggestion;
use App\Models\LegacyStagingRow;

/**
 * Step 7: every staged source row must end in exactly one state (mapped, needs_review,
 * rejected-with-reason) and the totals must equal the live source counts. Output is counts only.
 */
class LegacyReconciler
{
    public function __construct(private readonly LegacySource $source) {}

    /**
     * @return array{
     *     ok: bool, discrepancies: list<string>,
     *     source: array<string, int>, staged: array<string, int>,
     *     states: array<string, array<string, int>>, reasons: array<string, int>,
     *     maps: array<string, int>, suggestions: array<string, int>, signal_kinds: array<string, int>
     * }
     */
    public function reconcile(LegacyMigrationBatch $batch, bool $expectBaseline = false): array
    {
        LegacyGuard::assertNotProduction();
        if ($batch->status === 'rolled_back') {
            throw new LegacyException('This batch was rolled back; there is nothing to reconcile.');
        }

        $tables = array_keys((array) config('legacy.sources'));
        $discrepancies = [];

        $sourceCounts = [];
        foreach ($tables as $table) {
            $sourceCounts[$table] = $this->source->count($table);
            if ($expectBaseline && $sourceCounts[$table] !== (int) config("legacy.baseline_counts.{$table}")) {
                $discrepancies[] = "baseline_mismatch:{$table}";
            }
        }

        $states = [];
        $stagedCounts = array_fill_keys($tables, 0);
        $rows = LegacyStagingRow::query()->where('migration_batch_id', $batch->id)
            ->selectRaw('source_table, state, count(*) as c')->groupBy('source_table', 'state')
            ->orderBy('source_table')->orderBy('state')->get();
        foreach ($rows as $r) {
            $states[$r->source_table][$r->state] = (int) $r->getAttribute('c');
            $stagedCounts[$r->source_table] = ($stagedCounts[$r->source_table] ?? 0) + (int) $r->getAttribute('c');
        }

        $allowed = [LegacyStagingRow::STATE_MAPPED, LegacyStagingRow::STATE_NEEDS_REVIEW, LegacyStagingRow::STATE_REJECTED];
        foreach ($tables as $table) {
            if ($stagedCounts[$table] !== $sourceCounts[$table]) {
                $discrepancies[] = "count_mismatch:{$table}";
            }
            foreach ($states[$table] ?? [] as $state => $count) {
                if (! in_array($state, $allowed, true)) {
                    $discrepancies[] = ($state === LegacyStagingRow::STATE_STAGED ? 'unprocessed_rows' : 'invalid_state').":{$table}";
                }
            }
        }

        // Reason codes are mandatory for every row that is not cleanly mapped.
        $missingReason = LegacyStagingRow::query()->where('migration_batch_id', $batch->id)
            ->whereIn('state', [LegacyStagingRow::STATE_NEEDS_REVIEW, LegacyStagingRow::STATE_REJECTED])
            ->where(fn ($q) => $q->whereNull('reason')->orWhere('reason', ''))->count();
        if ($missingReason > 0) {
            $discrepancies[] = 'missing_reason';
        }

        $reasons = [];
        $reasonRows = LegacyStagingRow::query()->where('migration_batch_id', $batch->id)->whereNotNull('reason')
            ->selectRaw('reason, count(*) as c')->groupBy('reason')->get();
        foreach ($reasonRows as $r) {
            $key = explode(':', (string) $r->reason, 2)[0];
            $reasons[$key] = ($reasons[$key] ?? 0) + (int) $r->getAttribute('c');
        }
        ksort($reasons);

        // Exactly one identity map per mapped source row, and none for the others.
        $mapCounts = LegacyIdMap::query()->where('migration_batch_id', $batch->id)
            ->selectRaw('source_table, count(*) as c')->groupBy('source_table')->pluck('c', 'source_table')->all();
        foreach ($tables as $table) {
            $mapped = $states[$table][LegacyStagingRow::STATE_MAPPED] ?? 0;
            if ((int) ($mapCounts[$table] ?? 0) !== $mapped) {
                $discrepancies[] = "map_mismatch:{$table}";
            }
        }
        $orphans = LegacyIdMap::query()->where('migration_batch_id', $batch->id)
            ->whereNull('provider_id')->whereNull('featured_provider_id')->count();
        if ($orphans > 0) {
            $discrepancies[] = 'map_without_target';
        }

        $mapStatus = LegacyIdMap::query()->where('migration_batch_id', $batch->id)
            ->selectRaw('status, count(*) as c')->groupBy('status')->orderBy('status')->pluck('c', 'status')
            ->map(fn ($c) => (int) $c)->all();

        $suggestionStatus = LegacyPairSuggestion::query()->where('migration_batch_id', $batch->id)
            ->selectRaw('status, count(*) as c')->groupBy('status')->orderBy('status')->pluck('c', 'status')
            ->map(fn ($c) => (int) $c)->all();

        $kinds = [];
        LegacyPairSuggestion::query()->where('migration_batch_id', $batch->id)->select(['id', 'signals'])
            ->chunkById(1000, function ($chunk) use (&$kinds) {
                foreach ($chunk as $s) {
                    foreach ((array) $s->signals as $name) {
                        $kinds[$name] = ($kinds[$name] ?? 0) + 1;
                    }
                }
            });
        ksort($kinds);

        sort($discrepancies);

        return [
            'ok' => $discrepancies === [],
            'discrepancies' => array_values(array_unique($discrepancies)),
            'source' => $sourceCounts,
            'staged' => $stagedCounts,
            'states' => $states,
            'reasons' => $reasons,
            'maps' => $mapStatus,
            'suggestions' => $suggestionStatus,
            'signal_kinds' => $kinds,
        ];
    }
}
