<?php

namespace App\Services;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Administrator-managed account lifecycle. No e-mail is ever sent, required or verified: an
 * administrator creates accounts with a password of their choosing and resets forgotten passwords
 * (docs/10_DECISIONS.md ADR-020). Accounts are deactivated, never deleted.
 */
class UserService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** The one password policy used for creation, administrative reset and self-service change. */
    public static function passwordRule(): PasswordRule
    {
        return PasswordRule::min(12)->letters()->numbers();
    }

    /**
     * Create an account with an administrator-chosen password (entered twice). The address only has
     * to be a syntactically valid, unique identifier: the mailbox need not exist and is never contacted.
     *
     * @param  array<string, mixed>  $input  client input: name, email, role, password, password_confirmation, must_change_password
     */
    public function create(User $actor, array $input): User
    {
        $this->authorize($actor);

        // Canonicalize before validation so a mixed-case duplicate is a validation error, not a 500.
        if (isset($input['email']) && is_string($input['email'])) {
            $input['email'] = mb_strtolower(trim($input['email']));
        }

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(Role::class)],
            'password' => ['required', 'string', 'max:1024', 'confirmed', self::passwordRule()],
            'must_change_password' => ['sometimes', 'boolean'],
        ])->validate();

        $user = new User(['name' => trim($data['name']), 'email' => mb_strtolower($data['email']), 'password' => Hash::make($data['password'])]);
        $user->role = Role::from($data['role']);
        $user->is_active = true;
        $user->must_change_password = (bool) ($data['must_change_password'] ?? true);
        $user->save();

        $this->audit->record('user.created', $user, 'success', [
            'role' => $user->role->value, 'must_change_password' => $user->must_change_password,
        ], $actor);

        return $user;
    }

    /**
     * Administrative password reset: the new password is chosen by the administrator (entered twice),
     * every existing web session, "remember me" cookie and API token of the account is ended, and the
     * account can be forced to replace the password at its next sign-in. Nothing is e-mailed; the
     * password is never logged or audited.
     *
     * @param  array<string, mixed>  $input  password, password_confirmation, must_change_password
     */
    public function resetPassword(User $actor, User $target, array $input): User
    {
        $this->authorize($actor);

        if ($actor->getKey() === $target->getKey()) {
            // Ending the administrator's own session mid-action would be confusing: self-service is separate.
            throw ValidationException::withMessages(['password' => __('ui.users.reset_self')]);
        }

        $data = Validator::make($input, [
            'password' => ['required', 'string', 'max:1024', 'confirmed', self::passwordRule()],
            'must_change_password' => ['sometimes', 'boolean'],
        ])->validate();

        $mustChange = (bool) ($data['must_change_password'] ?? true);

        DB::transaction(function () use ($actor, $target, $data, $mustChange) {
            $locked = User::query()->lockForUpdate()->findOrFail($target->getKey());
            $locked->forceFill([
                'password' => Hash::make($data['password']),
                'must_change_password' => $mustChange,
            ])->save();
            $locked->revokeAllAccess();
            $this->audit->record('user.password_reset_by_admin', $locked, 'success', ['must_change_password' => $mustChange], $actor);
        });

        return $target->refresh();
    }

    public function changeRole(User $actor, User $target, Role $role): User
    {
        $this->authorize($actor);

        DB::transaction(function () use ($actor, $target, $role) {
            // Lock all admin rows so two concurrent demotions cannot both pass the last-admin check.
            $admins = User::query()->whereIn('role', Role::adminValues())->where('is_active', true)->lockForUpdate()->pluck('id');
            // Re-read the target under the lock: the in-memory model may be stale.
            $target = User::query()->lockForUpdate()->findOrFail($target->getKey());
            $isLastAdmin = $target->role === Role::Admin && $target->is_active
                && $admins->count() === 1 && $admins->contains($target->getKey());

            if ($isLastAdmin && $role !== Role::Admin) {
                throw ValidationException::withMessages(['role' => 'The last active administrator cannot be demoted.']);
            }

            $before = $target->role;
            $target->role = $role;
            $target->save();
            $this->audit->record('user.role_changed', $target, 'success',
                ['from' => $before?->value, 'to' => $role->value], $actor);
        });

        return $target->refresh();
    }

    public function setActive(User $actor, User $target, bool $active): User
    {
        $this->authorize($actor);

        DB::transaction(function () use ($actor, $target, $active) {
            $admins = User::query()->whereIn('role', Role::adminValues())->where('is_active', true)->lockForUpdate()->pluck('id');
            // Re-read the target under the lock: the in-memory model may be stale.
            $target = User::query()->lockForUpdate()->findOrFail($target->getKey());
            $isLastAdmin = $target->role === Role::Admin && $target->is_active
                && $admins->count() === 1 && $admins->contains($target->getKey());

            if (! $active && $isLastAdmin) {
                throw ValidationException::withMessages(['is_active' => 'The last active administrator cannot be deactivated.']);
            }

            $target->is_active = $active;
            $target->save();

            if (! $active) {
                // Revoke sessions, remember-me cookies and API tokens immediately: reactivating the
                // account later must not silently revive old credentials.
                $target->revokeAllAccess();
            }

            $this->audit->record($active ? 'user.activated' : 'user.deactivated', $target, 'success', [], $actor);
        });

        return $target->refresh();
    }

    private function authorize(User $actor): void
    {
        if (! Gate::forUser($actor)->allows(Permission::UsersManage->value)) {
            $this->audit->record('authorization.denied', null, 'denied', ['permission' => Permission::UsersManage->value], $actor);
            throw new AuthorizationException('This action is unauthorized.');
        }
    }
}
