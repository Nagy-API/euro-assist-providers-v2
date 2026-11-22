<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ExportJob;
use App\Models\User;

class ExportJobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::JobsViewOwn) || $user->hasPermission(Permission::JobsViewAny);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ExportsRun);
    }

    /** View and download: owner with jobs.view.own, or jobs.view.any. */
    public function view(User $user, ExportJob $job): bool
    {
        if ($user->hasPermission(Permission::JobsViewAny)) {
            return true;
        }

        return $user->hasPermission(Permission::JobsViewOwn) && $job->user_id === $user->getKey();
    }
}
