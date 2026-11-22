<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class AuditEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::AuditView);
    }
}
