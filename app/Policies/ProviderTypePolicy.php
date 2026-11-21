<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ProviderType;
use App\Models\User;

class ProviderTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ProvidersView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::TypesManage);
    }

    public function update(User $user, ProviderType $type): bool
    {
        return $user->hasPermission(Permission::TypesManage);
    }

    public function delete(User $user, ProviderType $type): bool
    {
        return false; // types referenced by providers are never deleted; deactivate instead
    }
}
