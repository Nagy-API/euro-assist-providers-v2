<?php

namespace App\Services\Transfers;

use App\Enums\Permission;
use App\Jobs\GenerateExportJob;
use App\Models\ExportJob;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Requests a provider export. The public signature is frozen (other areas call it).
 *
 * Snapshot semantics: rows are streamed in provider-id order while the job runs, so a provider edited
 * during generation appears in whichever state it had when its chunk was read (no global snapshot).
 * A provider that has no translation in the requested locale is SKIPPED and counted: the export
 * job shows total (matching providers) vs written rows.
 */
class ExportService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Authorize (exports.run), validate, create an ExportJob and queue generation.
     *
     * @param  string  $scope  client supplied: all|filtered|selected (validated below)
     * @param  string  $locale  client supplied: a configured locale (validated below)
     * @param  array<string, mixed>  $filters  provider list filters (search, type, city, district, status, trashed)
     * @param  array<int|string, mixed>  $selectedIds  client supplied ids (re-validated below)
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function dispatch(User $actor, string $scope, string $locale, array $filters = [], array $selectedIds = []): ExportJob
    {
        if (! Gate::forUser($actor)->allows(Permission::ExportsRun->value)) {
            $this->audit->record('authorization.denied', null, 'denied', ['permission' => Permission::ExportsRun->value], $actor);
            throw new AuthorizationException('This action is unauthorized.');
        }

        if (! in_array($scope, [ExportJob::SCOPE_ALL, ExportJob::SCOPE_FILTERED, ExportJob::SCOPE_SELECTED], true)) {
            throw ValidationException::withMessages(['scope' => __('transfers.errors.invalid_scope')]);
        }
        if (! in_array($locale, (array) config('providers.locales'), true)) {
            throw ValidationException::withMessages(['locale' => __('transfers.errors.invalid_locale')]);
        }

        $ids = null;
        if ($scope === ExportJob::SCOPE_SELECTED) {
            $ids = [];
            foreach ($selectedIds as $id) {
                if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                    $ids[(int) $id] = (int) $id;
                }
            }
            $ids = array_values($ids);
            sort($ids);
            $max = (int) config('providers.exports.selected_max');
            if ($ids === []) {
                throw ValidationException::withMessages(['selected_ids' => __('transfers.errors.no_selection')]);
            }
            if (count($ids) > $max) {
                throw ValidationException::withMessages(['selected_ids' => __('transfers.errors.too_many_selected', ['max' => $max])]);
            }
        }

        $clean = $scope === ExportJob::SCOPE_FILTERED ? ExportFilters::sanitize($filters, $actor->isAdmin()) : null;

        $job = ExportJob::query()->create([
            'user_id' => $actor->getKey(),
            'scope' => $scope,
            'locale' => $locale,
            'filters' => $clean === [] ? null : $clean,
            'selected_ids' => $ids,
            'status' => ExportJob::STATUS_QUEUED,
            'expires_at' => now()->addHours((int) config('providers.exports.retention_hours')),
        ]);

        $this->audit->record('export.requested', $job, 'success', [
            'scope' => $scope, 'locale' => $locale,
            'filters' => array_keys((array) $clean),
            'selected' => $ids === null ? null : count($ids),
        ], $actor);

        GenerateExportJob::dispatch($job->id);

        return $job->refresh();
    }
}
