<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Hand-written copy of the owner-approved matrix (ADR-010 Amendment A, docs/08_SECURITY.md).
 * It is deliberately NOT derived from Role::permissions(): the two must agree independently.
 */
class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const MATRIX = [
        // permission                 => [admin, editor, viewer]
        'providers.view' => [true, true, true],
        'providers.create' => [true, true, false],
        'providers.update' => [true, true, false],
        'providers.delete' => [true, false, false],
        'providers.restore' => [true, false, false],
        'providers.bulk.write' => [true, true, false],
        'providers.bulk.delete' => [true, false, false],
        'providers.clear_values' => [true, false, false],
        'types.manage' => [true, false, false],
        'featured.manage' => [true, false, false],
        'imports.run' => [true, true, false],
        'exports.run' => [true, true, false],
        'jobs.view.own' => [true, true, false],
        'jobs.view.any' => [true, false, false],
        'audit.view' => [true, false, false],
        'users.manage' => [true, false, false],
        'settings.manage' => [true, false, false],
        'migration.review' => [true, false, false],
    ];

    public function test_matrix_covers_every_permission_key(): void
    {
        $this->assertEqualsCanonicalizing(
            array_map(fn (Permission $p) => $p->value, Permission::cases()),
            array_keys(self::MATRIX),
            'Every permission must have an explicit matrix row and vice versa.'
        );
    }

    public function test_every_role_and_permission_cell_matches_the_approved_matrix(): void
    {
        $users = [
            'admin' => User::factory()->admin()->create(),
            'editor' => User::factory()->editor()->create(),
            'viewer' => User::factory()->viewer()->create(),
        ];

        foreach (self::MATRIX as $key => [$admin, $editor, $viewer]) {
            foreach (['admin' => $admin, 'editor' => $editor, 'viewer' => $viewer] as $role => $expected) {
                $this->assertSame(
                    $expected,
                    Gate::forUser($users[$role])->allows($key),
                    "Gate '$key' for role '$role' must be ".($expected ? 'allowed' : 'denied')
                );
                $this->assertSame($expected, $users[$role]->hasPermission(Permission::from($key)));
            }
        }
    }

    public function test_inactive_users_hold_no_permissions_at_all(): void
    {
        $inactive = User::factory()->admin()->inactive()->create();

        foreach (Permission::cases() as $permission) {
            $this->assertFalse(Gate::forUser($inactive)->allows($permission->value), $permission->value);
        }
    }

    public function test_only_three_roles_exist(): void
    {
        $this->assertSame(['admin', 'editor', 'viewer'], array_map(fn (Role $r) => $r->value, Role::cases()));
    }

    public function test_role_and_active_flag_are_not_mass_assignable(): void
    {
        $user = new User(['name' => 'X', 'email' => 'x@example.test', 'password' => 'p', 'role' => 'admin', 'is_active' => false]);

        $this->assertNull($user->role, 'role must not be mass-assignable');
        $this->assertNull($user->is_active);
    }

    public function test_new_accounts_default_to_the_least_privileged_role(): void
    {
        $id = \DB::table('users')->insertGetId(['name' => 'D', 'email' => 'd@example.test', 'password' => 'x']);

        $this->assertSame(Role::Viewer, User::query()->findOrFail($id)->role);
    }

    public function test_no_user_accounts_are_seeded(): void
    {
        $this->seed();

        $this->assertDatabaseCount('users', 0);
    }
}
