<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    /**
     * Stored legacy values that are read as one of the three real roles. `super_admin` was the legacy
     * system's only administrator role; it is an alias, never a fourth role.
     *
     * @var array<string, self>
     */
    public const LEGACY_ALIASES = ['super_admin' => self::Admin];

    /**
     * Every stored value that means Administrator (the real one plus legacy aliases), for queries that
     * must count administrators even before the normalization migration has run.
     *
     * @return list<string>
     */
    public static function adminValues(): array
    {
        return [self::Admin->value, ...array_keys(array_filter(self::LEGACY_ALIASES, fn (self $r) => $r === self::Admin))];
    }

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
