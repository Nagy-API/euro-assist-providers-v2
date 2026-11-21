<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\FeaturedProvider;
use App\Models\User;

class FeaturedProviderPolicy
{
    /** Viewing the featured list is part of the read-only dashboard. */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ProvidersView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::FeaturedManage);
    }

    public function update(User $user, FeaturedProvider $featured): bool
    {
        return $user->hasPermission(Permission::FeaturedManage);
    }

    public function delete(User $user, FeaturedProvider $featured): bool
    {
        return $user->hasPermission(Permission::FeaturedManage);
    }
}
