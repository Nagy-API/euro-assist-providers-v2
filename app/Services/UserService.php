<?php

namespace App\Services;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Create an account with an unusable random password and send a password-set invitation
     * through Laravel's password broker. No password is ever chosen, shown or logged here.
     *
     * @param  array<string, mixed>  $input  client input: name, email, role (validated below)
     */
    public function invite(User $actor, array $input): User
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
        ])->validate();

        $user = new User(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'password' => Hash::make(Str::random(64))]);
        $user->role = Role::from($data['role']);
        $user->is_active = true;
        $user->save();

        Password::sendResetLink(['email' => $user->email]);
        $this->audit->record('user.invited', $user, 'success', ['role' => $user->role->value], $actor);

        return $user;
    }

    public function changeRole(User $actor, User $target, Role $role): User
    {
        $this->authorize($actor);

        DB::transaction(function () use ($actor, $target, $role) {
            // Lock all admin rows so two concurrent demotions cannot both pass the last-admin check.
            $admins = User::query()->where('role', Role::Admin->value)->where('is_active', true)->lockForUpdate()->pluck('id');
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
            $admins = User::query()->where('role', Role::Admin->value)->where('is_active', true)->lockForUpdate()->pluck('id');
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
                // Revoke existing sessions immediately (database session driver).
                if (config('session.driver') === 'database') {
                    DB::table(config('session.table', 'sessions'))->where('user_id', $target->getKey())->delete();
                }
                $target->forceFill(['remember_token' => Str::random(60)])->save();
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
