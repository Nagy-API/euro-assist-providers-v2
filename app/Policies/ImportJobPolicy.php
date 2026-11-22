<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ImportJob;
use App\Models\User;

class ImportJobPolicy
{
    /** May open the import page / history list (list is scoped to own jobs unless jobs.view.any). */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::JobsViewOwn) || $user->hasPermission(Permission::JobsViewAny);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ImportsRun);
    }

    /** Own job (needs jobs.view.own) or any job (jobs.view.any). Used for error-report downloads too. */
    public function view(User $user, ImportJob $job): bool
    {
        if ($user->hasPermission(Permission::JobsViewAny)) {
            return true;
        }

        return $user->hasPermission(Permission::JobsViewOwn) && $job->user_id === $user->getKey();
    }

    /** Confirm/apply/cancel/retry: only the job owner who may run imports, or an Admin. */
    public function manage(User $user, ImportJob $job): bool
    {
        if (! $user->hasPermission(Permission::ImportsRun)) {
            return false;
        }

        return $job->user_id === $user->getKey() || $user->hasPermission(Permission::JobsViewAny);
    }
}
