<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Editor => [
                Permission::ProvidersView,
                Permission::ProvidersCreate,
                Permission::ProvidersUpdate,
                Permission::ProvidersBulkWrite,
                Permission::ImportsRun,
                Permission::ExportsRun,
                Permission::JobsViewOwn,
            ],
            self::Viewer => [Permission::ProvidersView],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function label(): string
    {
        return __('roles.'.$this->value);
    }
}
