<?php

namespace App\Services\Legacy;

use App\Models\LegacyMigrationBatch;
use App\Models\LegacyStagingRow;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Step 1: copy raw source rows into legacy_staging_rows (local database only) with a row hash.
 *
 * Idempotent and drift-aware: re-staging a batch inserts only missing rows, leaves unchanged rows
 * alone and updates (and re-opens) rows whose source content changed. A staged batch can be
 * re-staged any number of times; a batch that is already applied/rolled back cannot.
 */
class LegacyStager
{
    public function __construct(private readonly LegacySource $source, private readonly AuditLogger $audit) {}

    /**
     * @return array{batch: ?LegacyMigrationBatch, tables: array<string, array{source: int, inserted: int, unchanged: int, changed: int}>, dry_run: bool}
     */
    public function stage(string $snapshotSha, ?string $batchRef, bool $dryRun, ?User $actor = null): array
    {
        LegacyGuard::assertRunnable($snapshotSha);

        $version = (string) config('providers.legacy.mapping_version');
        $batch = $this->resolveBatch($snapshotSha, $version, $batchRef, $dryRun, $actor);

        $report = [];
        foreach (array_keys((array) config('legacy.sources')) as $table) {
            $report[$table] = ['source' => 0, 'inserted' => 0, 'unchanged' => 0, 'changed' => 0];
            $locale = config("legacy.sources.{$table}.locale");

            foreach ($this->source->chunks($table, (int) config('legacy.chunk_size', 500)) as $chunk) {
                $ids = array_map(fn (array $row) => (int) $row['id'], $chunk);
                $existing = $batch === null ? [] : LegacyStagingRow::query()
                    ->where('migration_batch_id', $batch->id)->where('source_table', $table)
                    ->whereIn('source_id', $ids)->pluck('row_hash', 'source_id')->all();

                $inserts = [];
                foreach ($chunk as $row) {
                    $report[$table]['source']++;
                    $id = (int) $row['id'];
                    $hash = self::rowHash($row);

                    if (! array_key_exists($id, $existing)) {
                        $report[$table]['inserted']++;
                        $inserts[] = $this->insertRow($batch, $table, $id, $locale, $hash, $row);
                    } elseif ($existing[$id] === $hash) {
                        $report[$table]['unchanged']++;
                    } else {
                        $report[$table]['changed']++;
                        if (! $dryRun) {
                            LegacyStagingRow::query()
                                ->where('migration_batch_id', $batch->id)->where('source_table', $table)->where('source_id', $id)
                                ->update([
                                    'row_hash' => $hash,
                                    'payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                                    'state' => LegacyStagingRow::STATE_STAGED,
                                    'reason' => null,
                                    'updated_at' => now(),
                                ]);
                        }
                    }
                }

                if (! $dryRun && $inserts !== []) {
                    DB::transaction(fn () => LegacyStagingRow::query()->insert($inserts));
                }
            }
        }

        if (! $dryRun && $batch !== null) {
            $counts = $batch->counts ?? [];
            $counts['source'] = array_map(fn (array $r) => $r['source'], $report);
            $counts['types'] = $this->source->count('types');
            $batch->forceFill(['counts' => $counts])->save();

            $this->audit->record('migration.batch.staged', $batch, 'success', [
                'batch_uuid' => $batch->uuid,
                'inserted' => array_sum(array_column($report, 'inserted')),
                'changed' => array_sum(array_column($report, 'changed')),
            ], $actor);
        }

        return ['batch' => $batch, 'tables' => $report, 'dry_run' => $dryRun];
    }

    private function resolveBatch(string $snapshotSha, string $version, ?string $ref, bool $dryRun, ?User $actor): ?LegacyMigrationBatch
    {
        if ($ref !== null && $ref !== '') {
            $batch = BatchLocator::find($ref);
            if ($batch->snapshot_sha256 !== strtolower($snapshotSha) || $batch->mapping_version !== $version) {
                throw new LegacyException('The batch was created for a different snapshot or mapping version.');
            }
            if ($batch->status !== 'staged') {
                throw new LegacyException("Batch status is '{$batch->status}'; only a staged batch can be re-staged.");
            }

            return $batch;
        }

        // Reuse the open staged batch of this snapshot/version instead of creating a duplicate.
        $open = LegacyMigrationBatch::query()
            ->where('snapshot_sha256', strtolower($snapshotSha))->where('mapping_version', $version)
            ->whereIn('status', ['staged', 'applied'])->orderByDesc('id')->first();
        if ($open !== null) {
            if ($open->status !== 'staged') {
                throw new LegacyException('This snapshot is already applied; roll the batch back before staging it again.');
            }

            return $open;
        }

        if ($dryRun) {
            return null;
        }

        return LegacyMigrationBatch::query()->create([
            'snapshot_sha256' => strtolower($snapshotSha),
            'mapping_version' => $version,
            'status' => 'staged',
            'is_rehearsal' => true,
            'counts' => [],
            'created_by' => $actor?->getKey(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function insertRow(?LegacyMigrationBatch $batch, string $table, int $id, ?string $locale, string $hash, array $row): array
    {
        return [
            'migration_batch_id' => $batch?->id,
            'source_table' => $table,
            'source_id' => $id,
            'source_locale' => $locale,
            'row_hash' => $hash,
            'payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'state' => LegacyStagingRow::STATE_STAGED,
            'reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Deterministic content hash of one source row (column order independent).
     *
     * @param  array<string, mixed>  $row
     */
    public static function rowHash(array $row): string
    {
        ksort($row);

        return hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
}
