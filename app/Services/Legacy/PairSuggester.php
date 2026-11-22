<?php

namespace App\Services\Legacy;

use App\Models\LegacyIdMap;
use App\Models\LegacyMigrationBatch;
use App\Models\LegacyPairSuggestion;
use Illuminate\Support\Facades\DB;

/**
 * Step 5: propose (never approve) English/Arabic pairs for human review.
 *
 * Signals are NAMES only (same_source_id, same_phone_normalized, same_map_url, same_coordinates).
 * A pair is suggested only when at least --min-signals DISTINCT signal kinds agree (default 2).
 * No signal alone, and certainly not a numeric id, a name or a shared phone, ever pairs rows.
 *
 * Memory is bounded: rows are indexed by key per signal kind and candidates are generated from
 * those indexes. A key shared by more than legacy.suggestions.max_group_pairs EN x AR pairs
 * (e.g. a brand-wide call centre number: ~259k phone-equal pairs in the frozen snapshot) is not
 * expanded at all; its pairs can still be found through a selective signal. At most
 * --max-per-row suggestions are kept per source row, best-scored first.
 */
class PairSuggester
{
    public const SIGNAL_SOURCE_ID = 'same_source_id';

    public const SIGNAL_PHONE = 'same_phone_normalized';

    public const SIGNAL_MAP = 'same_map_url';

    public const SIGNAL_COORDINATES = 'same_coordinates';

    public function __construct(private readonly RowNormalizer $normalizer) {}

    /**
     * @return array{rows: array{en: int, ar: int}, candidates: int, qualified: int, kept: int, created: int, existing: int, skipped_groups: int, signal_kinds: array<string, int>, dry_run: bool}
     */
    public function suggest(LegacyMigrationBatch $batch, int $minSignals, int $maxPerRow, bool $dryRun): array
    {
        LegacyGuard::assertNotProduction();
        $minSignals = max(1, min(4, $minSignals));
        $maxPerRow = max(1, $maxPerRow);
        $maxGroup = max(1, (int) config('legacy.suggestions.max_group_pairs', 200));

        [$features, $index] = $this->loadFeatures($batch);

        // Candidate generation from the per-kind indexes (bounded by the group cap).
        $candidates = [];
        $skipped = 0;
        foreach ($index as $keys) {
            foreach ($keys as $sides) {
                $en = $sides['en'] ?? [];
                $ar = $sides['ar'] ?? [];
                if ($en === [] || $ar === []) {
                    continue;
                }
                if (count($en) * count($ar) > $maxGroup) {
                    $skipped++;

                    continue;
                }
                foreach ($en as $e) {
                    foreach ($ar as $a) {
                        $candidates[$e.':'.$a] = [$e, $a];
                    }
                }
            }
        }

        // Score every candidate with ALL signal kinds.
        $scored = [];
        foreach ($candidates as [$e, $a]) {
            $signals = $this->signals($features['en'][$e], $features['ar'][$a]);
            if (count($signals) >= $minSignals) {
                $scored[] = [$e, $a, $signals];
            }
        }
        $candidateCount = count($candidates);
        unset($candidates);

        usort($scored, fn (array $x, array $y) => [count($y[2]), $x[0], $x[1]] <=> [count($x[2]), $y[0], $y[1]]);

        // Per-row cap (best-scored first, deterministic).
        $perRow = [];
        $kept = [];
        foreach ($scored as [$e, $a, $signals]) {
            if (($perRow['e'.$e] ?? 0) >= $maxPerRow || ($perRow['a'.$a] ?? 0) >= $maxPerRow) {
                continue;
            }
            $perRow['e'.$e] = ($perRow['e'.$e] ?? 0) + 1;
            $perRow['a'.$a] = ($perRow['a'.$a] ?? 0) + 1;
            $kept[] = [$e, $a, $signals];
        }

        $existing = LegacyPairSuggestion::query()->where('migration_batch_id', $batch->id)
            ->get(['left_map_id', 'right_map_id'])->mapWithKeys(fn ($s) => [$s->left_map_id.':'.$s->right_map_id => true])->all();

        $kinds = [];
        $created = 0;
        $already = 0;
        /** @var list<array<string, mixed>> $buffer filled in the loop below, flushed in chunks */
        $buffer = [];
        $flush = function () use (&$buffer, &$created, $dryRun) {
            if (! $dryRun && count($buffer) > 0) {
                DB::transaction(fn () => LegacyPairSuggestion::query()->insert($buffer));
            }
            $created += count($buffer);
            $buffer = [];
        };

        foreach ($kept as [$e, $a, $signals]) {
            if (isset($existing[$e.':'.$a])) {
                $already++;

                continue;
            }
            foreach ($signals as $name) {
                $kinds[$name] = ($kinds[$name] ?? 0) + 1;
            }
            $buffer[] = [
                'migration_batch_id' => $batch->id,
                'left_map_id' => $e,
                'right_map_id' => $a,
                'signals' => json_encode($signals, JSON_THROW_ON_ERROR),
                'signal_count' => count($signals),
                'status' => LegacyPairSuggestion::STATUS_SUGGESTED,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (count($buffer) >= (int) config('legacy.suggestions.insert_chunk', 500)) {
                $flush();
            }
        }
        $flush();
        ksort($kinds);

        return [
            'rows' => ['en' => count($features['en']), 'ar' => count($features['ar'])],
            'candidates' => $candidateCount,
            'qualified' => count($scored),
            'kept' => count($kept),
            'created' => $created,
            'existing' => $already,
            'skipped_groups' => $skipped,
            'signal_kinds' => $kinds,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @return array{0: array{en: array<int, array<string, mixed>>, ar: array<int, array<string, mixed>>}, 1: array<string, array<string, array{en?: list<int>, ar?: list<int>}>>}
     */
    private function loadFeatures(LegacyMigrationBatch $batch): array
    {
        $features = ['en' => [], 'ar' => []];
        $index = [self::SIGNAL_SOURCE_ID => [], self::SIGNAL_MAP => [], self::SIGNAL_COORDINATES => [], self::SIGNAL_PHONE => []];
        $sideOf = ['providers' => 'en', 'arabic_providers' => 'ar'];

        LegacyIdMap::query()
            ->join('legacy_staging_rows as s', function ($join) use ($batch) {
                $join->on('s.source_table', '=', 'legacy_id_maps.source_table')
                    ->on('s.source_id', '=', 'legacy_id_maps.source_id')
                    ->where('s.migration_batch_id', '=', $batch->id);
            })
            ->where('legacy_id_maps.migration_batch_id', $batch->id)
            ->where('legacy_id_maps.status', LegacyIdMap::STATUS_UNPAIRED)
            ->whereIn('legacy_id_maps.source_table', array_keys($sideOf))
            ->select(['legacy_id_maps.id as map_id', 'legacy_id_maps.source_table as map_table', 'legacy_id_maps.source_id as map_source_id', 's.payload as raw_payload'])
            ->chunkById(500, function ($rows) use (&$features, &$index, $sideOf) {
                foreach ($rows as $row) {
                    $side = $sideOf[$row->getAttribute('map_table')];
                    $payload = (array) json_decode((string) $row->getAttribute('raw_payload'), true);
                    $n = $this->normalizer->provider($payload);
                    $mapId = (int) $row->getAttribute('map_id');

                    $feature = [
                        self::SIGNAL_SOURCE_ID => (string) $row->getAttribute('map_source_id'),
                        self::SIGNAL_PHONE => $this->normalizer->phoneKey($n['phone']),
                        self::SIGNAL_MAP => $n['map_url'],
                        self::SIGNAL_COORDINATES => $n['latitude'] === null || $n['longitude'] === null
                            ? null : number_format($n['latitude'], 5, '.', '').','.number_format($n['longitude'], 5, '.', ''),
                    ];
                    $features[$side][$mapId] = $feature;
                    foreach ($feature as $kind => $key) {
                        if ($key !== null) {
                            $index[$kind][$key][$side][] = $mapId;
                        }
                    }
                }
            }, 'legacy_id_maps.id', 'map_id');

        return [$features, $index];
    }

    /**
     * @param  array<string, mixed>  $en
     * @param  array<string, mixed>  $ar
     * @return list<string>
     */
    private function signals(array $en, array $ar): array
    {
        $out = [];
        foreach ([self::SIGNAL_COORDINATES, self::SIGNAL_MAP, self::SIGNAL_PHONE, self::SIGNAL_SOURCE_ID] as $kind) {
            if ($en[$kind] !== null && $en[$kind] === $ar[$kind]) {
                $out[] = $kind;
            }
        }
        sort($out);

        return $out;
    }
}
