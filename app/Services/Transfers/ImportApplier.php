<?php

namespace App\Services\Transfers;

use App\Enums\Permission;
use App\Models\ImportJob;
use App\Models\ImportRow;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies a validated, confirmed import in bounded chunks.
 *
 * - Preconditions (guard): not dry_run, confirmed by an authorized user, status `applying`, zero
 *   error rows, staged rows intact, not expired. Otherwise ImportNotApplicable and ZERO writes.
 * - One global lock (imports:apply) serializes imports; a busy lock raises ImportLockBusy.
 * - Each chunk = ONE transaction: providers locked (FOR UPDATE, id order), base_revision compared,
 *   rows written via ProviderImportWriter, rows marked applied and applied_count bumped in the SAME
 *   transaction, so committed progress is the real progress.
 * - Only `pending` rows are processed, so a re-run after a crash is deterministic and idempotent.
 *   A created row stores its provider_id in the same transaction as the insert.
 * - A revision mismatch rolls the chunk back, marks that row `conflict` and ends the job as
 *   `conflict` (earlier chunks stay committed; the user must re-preview).
 */
class ImportApplier
{
    public const LOCK_KEY = 'imports:apply';

    public function __construct(
        private readonly ProviderImportWriter $writer,
        private readonly AuditLogger $audit,
        private readonly ImportJobService $jobs,
    ) {}

    /**
     * @throws ImportNotApplicable
     * @throws ImportLockBusy
     */
    public function apply(ImportJob $job): void
    {
        $this->guard($job);

        $lock = $this->lockStore()->lock(self::LOCK_KEY, (int) config('providers.imports.lock_seconds'));
        if (! $lock->get()) {
            throw new ImportLockBusy('imports:apply is held');
        }

        try {
            $job = ImportJob::query()->findOrFail($job->id);
            $actor = $this->guard($job); // re-check inside the lock: state may have changed while waiting

            if ($this->jobs->duplicateBlocker($job) !== null) {
                throw new ImportNotApplicable('already_imported', failJob: true);
            }

            $job->forceFill(['started_at' => $job->started_at ?? now(), 'attempts' => $job->attempts + 1])->save();
            $chunkSize = max(1, (int) $job->chunk_size);
            $chunkIndex = 0;

            while (true) {
                /** @var Collection<int, ImportRow> $rows */
                $rows = ImportRow::query()
                    ->where('import_job_id', $job->id)
                    ->where('apply_status', ImportRow::APPLY_PENDING)
                    ->orderBy('row_number')
                    ->limit($chunkSize)
                    ->get();
                if ($rows->isEmpty()) {
                    break;
                }
                $this->assertLockHeld($lock);

                try {
                    $this->applyChunk($job, $actor, $rows, $chunkIndex);
                } catch (ImportConflict $conflict) {
                    $this->markConflict($job, $conflict);

                    return;
                }
                $chunkIndex++;
            }

            $this->complete($job, $actor, $chunkIndex);
        } finally {
            $lock->release();
        }
    }

    /**
     * Mark a confirmed, applying job as failed/interrupted after an unrecoverable error. Never
     * downgrades a job that is already completed/conflict/cancelled.
     */
    public function markFailed(ImportJob $job, ?Throwable $e, string $reason = 'apply_failed'): void
    {
        $fresh = ImportJob::query()->find($job->id);
        if ($fresh === null || $fresh->status !== ImportJob::STATUS_APPLYING) {
            return;
        }

        $applied = ImportRow::query()->where('import_job_id', $fresh->id)->where('apply_status', ImportRow::APPLY_APPLIED)->exists();
        $fresh->forceFill([
            'status' => $applied ? ImportJob::STATUS_INTERRUPTED : ImportJob::STATUS_FAILED,
            'failure_reason' => $e !== null ? mb_substr(class_basename($e), 0, 100) : $reason,
            'finished_at' => now(),
        ])->save();

        $this->audit->record('import.failed', $fresh, 'failure', [
            'applied' => $fresh->applied_count,
            'reason' => $fresh->failure_reason,
            'resumable' => $applied,
        ], $this->actorFor($fresh));
        Log::warning('import.apply.failed', ['job' => $fresh->uuid, 'class' => $e !== null ? $e::class : $reason, 'applied' => $fresh->applied_count]);
    }

    // ------------------------------------------------------------------ guard

    /**
     * @throws ImportNotApplicable
     */
    public function guard(ImportJob $job): User
    {
        $job->refresh();

        if ($job->mode === ImportJob::MODE_DRY_RUN) {
            throw new ImportNotApplicable('dry_run');
        }
        if ($job->confirmed_at === null || $job->confirmed_by === null) {
            throw new ImportNotApplicable('not_confirmed');
        }
        if ($job->status !== ImportJob::STATUS_APPLYING) {
            throw new ImportNotApplicable('not_applying');
        }
        if ($job->error_count > 0 || ImportRow::query()->where('import_job_id', $job->id)->where('action', ImportRow::ACTION_ERROR)->exists()) {
            throw new ImportNotApplicable('has_errors', failJob: true);
        }
        if ($job->expires_at !== null && $job->expires_at->isPast()) {
            throw new ImportNotApplicable('expired', failJob: true);
        }
        $staged = ImportRow::query()->where('import_job_id', $job->id)->count();
        if ($staged !== (int) $job->total_rows) {
            throw new ImportNotApplicable('staging_missing', failJob: true);
        }

        $actor = User::query()->find($job->confirmed_by);
        $gate = $actor !== null ? Gate::forUser($actor) : null;
        if ($actor === null || ! $gate?->allows(Permission::ImportsRun->value)
            || ($job->insert_count > 0 && ! $gate->allows(Permission::ProvidersCreate->value))
            || ($job->update_count > 0 && ! $gate->allows(Permission::ProvidersUpdate->value))) {
            throw new ImportNotApplicable('actor_not_authorized', failJob: true);
        }

        return $actor;
    }

    // ------------------------------------------------------------------ chunk

    /**
     * @param  Collection<int, ImportRow>  $rows
     *
     * @throws ImportConflict
     */
    private function applyChunk(ImportJob $job, User $actor, Collection $rows, int $chunkIndex): void
    {
        DB::transaction(function () use ($job, $actor, $rows, $chunkIndex): void {
            $updateIds = $rows->where('action', ImportRow::ACTION_UPDATE)->pluck('provider_id')->unique()->sort()->values()->all();
            /** @var Collection<int, Provider> $providers */
            $providers = collect();
            if ($updateIds !== []) {
                $providers = Provider::query()->withTrashed()->whereIn('id', $updateIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $providers->load('translations');
            }

            $typeIds = $rows->where('action', ImportRow::ACTION_CREATE)
                ->map(fn (ImportRow $r) => (int) ($r->payload['provider_type_id'] ?? 0))->unique()->values()->all();
            $knownTypes = $typeIds === [] ? [] : ProviderType::query()->whereIn('id', $typeIds)->pluck('id')->all();

            $created = [];
            $writtenRows = [];
            foreach ($rows as $row) {
                $payload = (array) $row->payload;

                if ($row->action === ImportRow::ACTION_UPDATE) {
                    $provider = $providers->get($row->provider_id);
                    if ($provider === null || $provider->trashed() || (int) $provider->revision !== (int) $row->base_revision) {
                        throw new ImportConflict($row->id, $row->row_number);
                    }
                    $this->writer->update($actor, $provider, $payload);
                } elseif ($row->action === ImportRow::ACTION_CREATE) {
                    if (! in_array((int) ($payload['provider_type_id'] ?? 0), $knownTypes, true)) {
                        throw new ImportConflict($row->id, $row->row_number);
                    }
                    $created[$row->id] = $this->writer->create($actor, $payload)->id;
                } else {
                    continue; // unchanged/error rows are never pending; defensive
                }
                $writtenRows[] = $row->id;
            }

            event(new ImportChunkWritten($job, $chunkIndex, count($writtenRows)));

            $now = now();
            $updatedRows = array_values(array_diff($writtenRows, array_keys($created)));
            if ($updatedRows !== []) {
                ImportRow::query()->whereIn('id', $updatedRows)->update(['apply_status' => ImportRow::APPLY_APPLIED, 'applied_at' => $now]);
            }
            foreach ($created as $rowId => $providerId) {
                ImportRow::query()->whereKey($rowId)->update(['apply_status' => ImportRow::APPLY_APPLIED, 'applied_at' => $now, 'provider_id' => $providerId]);
            }
            ImportJob::query()->whereKey($job->id)->update([
                'applied_count' => DB::raw('applied_count + '.count($writtenRows)),
                'updated_at' => $now,
            ]);
        });
    }

    private function markConflict(ImportJob $job, ImportConflict $conflict): void
    {
        ImportRow::query()->whereKey($conflict->importRowId)->update(['apply_status' => ImportRow::APPLY_CONFLICT]);
        $job->refresh();
        $job->forceFill([
            'status' => ImportJob::STATUS_CONFLICT,
            'failure_reason' => 'conflict_row_'.$conflict->rowNumber,
            'finished_at' => now(),
        ])->save();

        $this->audit->record('import.failed', $job, 'failure', [
            'reason' => 'conflict', 'row' => $conflict->rowNumber, 'applied' => $job->applied_count,
        ], $this->actorFor($job));
        Log::warning('import.apply.conflict', ['job' => $job->uuid, 'row' => $conflict->rowNumber, 'applied' => $job->applied_count]);
    }

    private function complete(ImportJob $job, User $actor, int $chunks): void
    {
        $job->refresh();
        $left = ImportRow::query()->where('import_job_id', $job->id)->whereIn('apply_status', [ImportRow::APPLY_PENDING, ImportRow::APPLY_CONFLICT])->exists();
        if ($left) {
            $this->markFailed($job, null, 'rows_remaining');

            return;
        }

        $counts = ImportRow::query()
            ->where('import_job_id', $job->id)
            ->where('apply_status', ImportRow::APPLY_APPLIED)
            ->selectRaw('action, count(*) as n')
            ->groupBy('action')
            ->pluck('n', 'action');

        $job->forceFill(['status' => ImportJob::STATUS_COMPLETED, 'finished_at' => now(), 'failure_reason' => null])->save();

        $this->audit->record('import.applied', $job, 'success', [
            'created' => (int) ($counts[ImportRow::ACTION_CREATE] ?? 0),
            'updated' => (int) ($counts[ImportRow::ACTION_UPDATE] ?? 0),
            'unchanged' => $job->unchanged_count,
            'rows' => $job->total_rows,
            'chunks_this_run' => $chunks,
            'attempts' => $job->attempts,
            'mode' => $job->mode,
        ], $actor);
        Log::info('import.apply.completed', ['job' => $job->uuid, 'applied' => $job->applied_count, 'rows' => $job->total_rows]);
    }

    private function actorFor(ImportJob $job): ?User
    {
        return ($job->confirmed_by !== null ? User::query()->find($job->confirmed_by) : null) ?? $job->user;
    }

    // ------------------------------------------------------------------ lock

    /** The configured cache store must support atomic locks (database, redis, file, array do). */
    private function lockStore(): LockProvider
    {
        $name = config('providers.imports.lock_store');
        $store = ($name ? Cache::store((string) $name) : Cache::store())->getStore();
        if (! $store instanceof LockProvider) {
            throw new \RuntimeException('The import lock store does not support atomic locks.');
        }

        return $store;
    }

    private function assertLockHeld(Lock $lock): void
    {
        if (method_exists($lock, 'isOwnedByCurrentProcess') && ! $lock->isOwnedByCurrentProcess()) {
            throw new ImportLockBusy('imports:apply lock was lost');
        }
    }
}
