<?php

namespace App\Casts;

use App\Enums\Role;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Tolerant reader for users.role.
 *
 * The application has exactly three roles (Administrator, Editor, Viewer). The legacy system stored
 * `super_admin` for its only administrator, and a database that was loaded with such a value must
 * neither crash the application (a plain enum cast throws on unknown values) nor silently demote or
 * lock out that account:
 *
 *  - `super_admin` is read as Administrator (the same privileges the legacy account always had);
 *  - any other unknown value reads as null = NO permissions (fail closed; it is never promoted).
 *
 * Writes accept only the three real roles. The `normalize_legacy_super_admin_role` migration rewrites
 * stored `super_admin` values to `admin` once, with an audit event per account.
 *
 * @implements CastsAttributes<Role|null, Role|string|null>
 */
class UserRole implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Role
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Role::tryFrom($value) ?? (Role::LEGACY_ALIASES[$value] ?? null);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $role = match (true) {
            $value instanceof Role => $value,
            is_string($value) => Role::tryFrom($value),
            default => null,
        };

        if ($role === null) {
            throw new InvalidArgumentException('Unknown role: only administrator, editor and viewer exist.');
        }

        return [$key => $role->value];
    }
}
