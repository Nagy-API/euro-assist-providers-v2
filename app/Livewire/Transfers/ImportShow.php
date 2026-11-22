<?php

namespace App\Livewire\Transfers;

use App\Models\ImportJob;
use App\Models\ImportRow;
use App\Services\Transfers\ErrorMessages;
use App\Services\Transfers\ImportJobService;
use App\Services\Transfers\JobAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Status, counts, preview diff and errors of one import job. Read-only: confirm/cancel/retry are
 * CSRF POST forms handled by ImportController. Authorization runs on mount AND on every render
 * (polling), and only the job's uuid is kept in component state.
 */
#[Layout('components.layouts.app')]
class ImportShow extends Component
{
    use WithPagination;

    public string $uuid = '';

    public function mount(ImportJob $importJob): void
    {
        JobAccess::view(auth()->user(), $importJob);
        $this->uuid = $importJob->uuid;
    }

    public function render(): View
    {
        $user = auth()->user();
        $job = ImportJob::query()->where('uuid', $this->uuid)->firstOrFail();
        JobAccess::view($user, $job);

        $perPage = (int) config('providers.imports.preview_per_page');
        $summary = (array) $job->error_summary;

        $diffs = ImportRow::query()
            ->where('import_job_id', $job->id)
            ->whereIn('action', [ImportRow::ACTION_CREATE, ImportRow::ACTION_UPDATE])
            ->orderBy('row_number')
            ->paginate($perPage, ['*'], 'diffs');

        $errors = ImportRow::query()
            ->where('import_job_id', $job->id)
            ->where('action', ImportRow::ACTION_ERROR)
            ->orderBy('row_number')
            ->paginate($perPage, ['*'], 'errors');

        $warnings = [];
        foreach ((array) ($summary['warnings'] ?? []) as $code => $info) {
            $warnings[] = ['message' => ErrorMessages::warning((string) $code), 'count' => $info['count'] ?? 0, 'rows' => $info['rows'] ?? []];
        }

        $fileErrors = array_map(fn (array $e) => ErrorMessages::file($e), (array) ($summary['file_errors'] ?? []));

        $stale = $job->status === ImportJob::STATUS_APPLYING
            && $job->updated_at !== null
            && $job->updated_at->lt(now()->subMinutes((int) config('providers.imports.stale_apply_minutes')));

        return view('livewire.transfers.import-show', [
            'job' => $job,
            'diffRows' => $diffs,
            'errorRows' => $errors,
            'warnings' => $warnings,
            'fileErrors' => $fileErrors,
            'unknownColumns' => (array) ($summary['unknown_columns'] ?? []),
            'polling' => in_array($job->status, [ImportJob::STATUS_UPLOADED, ImportJob::STATUS_VALIDATING, ImportJob::STATUS_APPLYING], true),
            'canManage' => $user->can('manage', $job),
            'canConfirm' => $job->status === ImportJob::STATUS_READY && $job->mode !== ImportJob::MODE_DRY_RUN && $job->error_count === 0,
            'canCancel' => in_array($job->status, ImportJobService::CANCELLABLE, true),
            'canRetry' => in_array($job->status, [ImportJob::STATUS_INTERRUPTED, ImportJob::STATUS_FAILED], true) || $stale,
            'partial' => $job->applied_count > 0 && $job->status !== ImportJob::STATUS_COMPLETED,
        ])->title(__('transfers.imports.show_title'));
    }
}
