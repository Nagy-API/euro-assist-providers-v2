<?php

namespace App\Services\Transfers;

use App\Enums\Permission;
use App\Jobs\ApplyImportJob;
use App\Jobs\ValidateImportJob;
use App\Models\ImportJob;
use App\Models\ImportRow;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Entry points that change import-job state: upload, confirm, cancel, retry. Each one re-checks
 * authorization itself (controllers authorize too; this is defense in depth for any other caller).
 */
class ImportJobService
{
    /** Statuses from which a job may be cancelled. Cancelling never rolls back applied chunks. */
    public const CANCELLABLE = [
        ImportJob::STATUS_UPLOADED, ImportJob::STATUS_VALIDATING, ImportJob::STATUS_READY,
        ImportJob::STATUS_INTERRUPTED, ImportJob::STATUS_CONFLICT,
    ];

    public function __construct(
        private readonly ImportFileInspector $inspector,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------ upload

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function upload(User $actor, UploadedFile $file, string $mode): ImportJob
    {
        $this->authorize($actor, 'create', ImportJob::class);

        if (! in_array($mode, ImportJob::MODES, true)) {
            throw ValidationException::withMessages(['mode' => __('transfers.errors.invalid_mode')]);
        }
        if (! $file->isValid()) {
            $this->rejectUpload($actor, 'upload_failed');
        }

        $clientName = (string) $file->getClientOriginalName();
        if (strtolower(pathinfo($clientName, PATHINFO_EXTENSION)) !== 'xlsx') {
            $this->rejectUpload($actor, 'bad_extension');
        }

        $path = (string) $file->getRealPath();
        try {
            $this->inspector->inspect($path); // size, zip signature, bomb guard, macro/links/objects
        } catch (ImportRejected $e) {
            $this->rejectUpload($actor, $e->errorCode, $e->params);
        }

        $cfg = config('providers.imports');
        $disk = (string) $cfg['disk'];
        $stored = PrivateFiles::newPath((string) $cfg['directory']);
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            $this->rejectUpload($actor, 'upload_failed');
        }
        try {
            Storage::disk($disk)->put($stored, $stream);
        } finally {
            fclose($stream);
        }

        $job = ImportJob::query()->create([
            'user_id' => $actor->getKey(),
            'mode' => $mode,
            'status' => ImportJob::STATUS_UPLOADED,
            'original_filename' => PrivateFiles::displayName($clientName),
            'stored_path' => $stored,
            'file_sha256' => (string) hash_file('sha256', $path),
            'file_size' => (int) filesize($path),
            'chunk_size' => (int) $cfg['chunk_size'],
            'expires_at' => now()->addHours((int) $cfg['retention_hours']),
        ]);

        $this->audit->record('import.uploaded', $job, 'success', [
            'mode' => $mode, 'size' => $job->file_size, 'sha256' => $job->file_sha256,
        ], $actor);

        ValidateImportJob::dispatch($job->id);

        return $job->refresh();
    }

    /** @param array<string, scalar> $params */
    private function rejectUpload(User $actor, string $code, array $params = []): never
    {
        $this->audit->record('import.upload_rejected', null, 'failure', ['reason' => $code], $actor);

        throw ValidationException::withMessages([
            'file' => ErrorMessages::file(['code' => $code, 'params' => $params]),
        ]);
    }

    // ------------------------------------------------------------------ confirm

    /**
     * Explicit confirmation of a validated preview. Only a `ready`, non-dry-run job with zero errors
     * can be confirmed; everything else is rejected without any state change.
     *
     * @throws AuthorizationException
     * @throws ImportActionRejected
     */
    public function confirm(User $actor, ImportJob $job): ImportJob
    {
        $this->authorize($actor, 'manage', $job);

        DB::transaction(function () use ($actor, $job): void {
            $locked = ImportJob::query()->lockForUpdate()->findOrFail($job->id);

            if ($locked->status !== ImportJob::STATUS_READY) {
                throw new ImportActionRejected('not_ready');
            }
            if ($locked->mode === ImportJob::MODE_DRY_RUN) {
                throw new ImportActionRejected('dry_run');
            }
            if ($locked->error_count > 0 || ImportRow::query()->where('import_job_id', $locked->id)->where('action', ImportRow::ACTION_ERROR)->exists()) {
                throw new ImportActionRejected('has_errors');
            }
            if ($locked->expires_at !== null && $locked->expires_at->isPast()) {
                throw new ImportActionRejected('expired');
            }
            if (ImportRow::query()->where('import_job_id', $locked->id)->count() !== (int) $locked->total_rows) {
                throw new ImportActionRejected('staging_missing');
            }
            if ($this->duplicateBlocker($locked) !== null) {
                throw new ImportActionRejected('already_imported');
            }

            $locked->forceFill([
                'status' => ImportJob::STATUS_APPLYING,
                'confirmed_by' => $actor->getKey(),
                'confirmed_at' => now(),
                'finished_at' => null,
                'failure_reason' => null,
            ])->save();

            $this->audit->record('import.confirmed', $locked, 'success', [
                'mode' => $locked->mode, 'inserts' => $locked->insert_count, 'updates' => $locked->update_count,
                'unchanged' => $locked->unchanged_count, 'rows' => $locked->total_rows,
            ], $actor);
        });

        ApplyImportJob::dispatch($job->id);

        return $job->refresh();
    }

    /**
     * The job whose earlier (or completed) application of the SAME file makes confirming this one
     * unsafe: a COMPLETED non-dry-run job with the same SHA-256, or an older job of the same file that
     * is still applying/interrupted. Only create-capable jobs are blocked (re-applying unchanged
     * updates is harmless and produces zero changes).
     */
    public function duplicateBlocker(ImportJob $job): ?ImportJob
    {
        if ($job->insert_count < 1) {
            return null;
        }

        return ImportJob::query()
            ->where('file_sha256', $job->file_sha256)
            ->where('id', '!=', $job->id)
            ->where('mode', '!=', ImportJob::MODE_DRY_RUN)
            ->where('insert_count', '>', 0)
            ->where(function ($q) use ($job) {
                $q->where('status', ImportJob::STATUS_COMPLETED)
                    ->orWhere(function ($q) use ($job) {
                        $q->whereIn('status', [ImportJob::STATUS_APPLYING, ImportJob::STATUS_INTERRUPTED])
                            ->where('id', '<', $job->id);
                    });
            })
            ->first();
    }

    // ------------------------------------------------------------------ cancel

    /** @throws ImportActionRejected */
    public function cancel(User $actor, ImportJob $job): ImportJob
    {
        $this->authorize($actor, 'manage', $job);

        $updated = ImportJob::query()
            ->whereKey($job->id)
            ->whereIn('status', self::CANCELLABLE)
            ->update(['status' => ImportJob::STATUS_CANCELLED, 'finished_at' => now(), 'updated_at' => now()]);
        if ($updated === 0) {
            throw new ImportActionRejected('cannot_cancel');
        }

        $job->refresh();
        $this->audit->record('import.cancelled', $job, 'success', ['applied' => $job->applied_count, 'rows' => $job->total_rows], $actor);

        return $job;
    }

    // ------------------------------------------------------------------ retry

    /**
     * Resume a confirmed job that was interrupted/failed (or is stuck applying), or re-run a
     * validation that crashed. Only `pending` rows are ever (re)applied.
     *
     * @throws ImportActionRejected
     */
    public function retry(User $actor, ImportJob $job): ImportJob
    {
        $this->authorize($actor, 'manage', $job);

        $resume = DB::transaction(function () use ($job): string {
            $locked = ImportJob::query()->lockForUpdate()->findOrFail($job->id);
            $stale = $locked->status === ImportJob::STATUS_APPLYING
                && $locked->updated_at !== null
                && $locked->updated_at->lt(now()->subMinutes((int) config('providers.imports.stale_apply_minutes')));

            if ($locked->confirmed_at !== null
                && ($stale || in_array($locked->status, [ImportJob::STATUS_INTERRUPTED, ImportJob::STATUS_FAILED], true))) {
                $locked->forceFill(['status' => ImportJob::STATUS_APPLYING, 'finished_at' => null, 'failure_reason' => null])->save();

                return 'apply';
            }
            if ($locked->confirmed_at === null && $locked->status === ImportJob::STATUS_FAILED) {
                $locked->forceFill(['status' => ImportJob::STATUS_UPLOADED, 'finished_at' => null, 'failure_reason' => null])->save();

                return 'validate';
            }

            throw new ImportActionRejected('cannot_retry');
        });

        $this->audit->record('import.retried', $job, 'success', ['resume' => $resume], $actor);
        $resume === 'apply' ? ApplyImportJob::dispatch($job->id) : ValidateImportJob::dispatch($job->id);

        return $job->refresh();
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  class-string|ImportJob  $subject
     *
     * @throws AuthorizationException
     */
    private function authorize(User $actor, string $ability, string|ImportJob $subject): void
    {
        if (! Gate::forUser($actor)->allows($ability, $subject)) {
            $this->audit->record('authorization.denied', $subject instanceof ImportJob ? $subject : null, 'denied', [
                'permission' => $ability === 'create' ? Permission::ImportsRun->value : 'imports.manage',
            ], $actor);
            throw new AuthorizationException('This action is unauthorized.');
        }
    }
}
