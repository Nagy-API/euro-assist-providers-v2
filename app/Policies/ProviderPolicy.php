<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Provider;
use App\Models\User;

class ProviderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ProvidersView);
    }

    public function view(User $user, Provider $provider): bool
    {
        return $user->hasPermission(Permission::ProvidersView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ProvidersCreate);
    }

    public function update(User $user, Provider $provider): bool
    {
        return $user->hasPermission(Permission::ProvidersUpdate);
    }

    public function delete(User $user, Provider $provider): bool
    {
        return $user->hasPermission(Permission::ProvidersDelete);
    }

    public function restore(User $user, Provider $provider): bool
    {
        // A merge shell is re-split only by undoing its pair in the migration review (ADR-017).
        return $provider->merged_into_id === null && $user->hasPermission(Permission::ProvidersRestore);
    }

    public function forceDelete(User $user, Provider $provider): bool
    {
        return false; // hard deletion is never offered
    }

    public function bulkWrite(User $user): bool
    {
        return $user->hasPermission(Permission::ProvidersBulkWrite);
    }

    public function bulkDelete(User $user): bool
    {
        return $user->hasPermission(Permission::ProvidersBulkDelete);
    }

    public function clearValues(User $user): bool
    {
        return $user->hasPermission(Permission::ProvidersClearValues);
    }
}
