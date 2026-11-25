<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use App\Livewire\Users\Index as UsersIndex;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private UserService $users;

    public function test_users_page_flags_migrated_accounts_awaiting_activation(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'is_active' => true]);
        $migrated = User::factory()->create(['email' => 'migrated@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $migrated->setRawAttributes(['password' => User::ACTIVATION_REQUIRED_PASSWORD] + $migrated->getAttributes())->save();

        $this->assertTrue($migrated->fresh()->requiresActivation());
        $this->assertFalse($admin->requiresActivation());
        Livewire::actingAs($admin)->test(UsersIndex::class)->assertSee(__('ui.users.activation_pending'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = app(UserService::class);
    }

    private function pw(string $password = 'Correct-Horse-9999'): array
    {
        return ['password' => $password, 'password_confirmation' => $password];
    }

    public function test_admin_creates_a_user_with_a_password_and_no_email_is_sent(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();

        $user = $this->users->create($admin, ['name' => 'New Person', 'email' => 'New@Example.test', 'role' => 'editor'] + $this->pw());

        $this->assertSame('new@example.test', $user->email);
        $this->assertSame(Role::Editor, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->must_change_password, 'default: the person replaces the password the admin chose');
        $this->assertTrue(\Hash::check('Correct-Horse-9999', $user->password));
        $this->assertNull($user->email_verified_at, 'no e-mail verification is required or recorded');
        Notification::assertNothingSent();
        $this->assertDatabaseHas('audit_events', ['action' => 'user.created', 'actor_id' => $admin->id]);
        $this->assertStringNotContainsString('Correct-Horse', json_encode(\DB::table('audit_events')->pluck('metadata')));
    }

    public function test_a_nonexistent_but_syntactically_valid_mailbox_is_accepted(): void
    {
        $admin = User::factory()->admin()->create();

        $user = $this->users->create($admin, ['name' => 'Ghost Mailbox', 'email' => 'nobody.here@no-such-domain-xyz.invalid', 'role' => 'viewer'] + $this->pw());

        $this->assertSame('nobody.here@no-such-domain-xyz.invalid', $user->email);

        $this->expectException(ValidationException::class);
        $this->users->create($admin, ['name' => 'Bad', 'email' => 'not-an-email', 'role' => 'viewer'] + $this->pw());
    }

    public function test_creation_requires_a_matching_strong_password(): void
    {
        $admin = User::factory()->admin()->create();
        $base = ['name' => 'X', 'email' => 'x@example.test', 'role' => 'viewer'];

        foreach ([
            [],                                                          // missing
            ['password' => 'Correct-Horse-9999'],                          // no confirmation
            ['password' => 'Correct-Horse-9999', 'password_confirmation' => 'Different-Horse-9999'],
            ['password' => 'short1', 'password_confirmation' => 'short1'], // too weak
            ['password' => 'onlyletterslongenough', 'password_confirmation' => 'onlyletterslongenough'],
        ] as $pw) {
            try {
                $this->users->create($admin, $base + $pw);
                $this->fail('weak or unconfirmed password accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('password', $e->errors());
            }
        }
        $this->assertDatabaseMissing('users', ['email' => 'x@example.test']);
    }

    public function test_only_the_three_roles_can_be_assigned_and_duplicates_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'taken@example.test']);

        foreach (['superuser', 'super_admin', ''] as $role) {
            try {
                $this->users->create($admin, ['name' => 'X', 'email' => 'new'.md5($role).'@example.test', 'role' => $role] + $this->pw());
                $this->fail('unknown role accepted: '.$role);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('role', $e->errors());
            }
        }

        $this->expectException(ValidationException::class);
        $this->users->create($admin, ['name' => 'X', 'email' => 'taken@example.test', 'role' => 'viewer'] + $this->pw());
    }

    public function test_admin_password_reset_replaces_the_hash_ends_access_and_forces_a_change(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->admin()->create();
        $target = User::factory()->editor()->create();
        $oldHash = $target->password;
        \DB::table('sessions')->insert(['id' => 's-old', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);
        $remember = $target->remember_token;

        $this->users->resetPassword($admin, $target, $this->pw('Reset-Pass-Phrase-77') + ['must_change_password' => true]);

        $fresh = $target->fresh();
        $this->assertNotSame($oldHash, $fresh->password);
        $this->assertTrue(\Hash::check('Reset-Pass-Phrase-77', $fresh->password));
        $this->assertTrue($fresh->must_change_password);
        $this->assertNotSame($remember, $fresh->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 's-old']);
        $this->assertSame(Role::Editor, $fresh->role, 'a reset never changes role or activity');
        $this->assertTrue($fresh->is_active);
        $this->assertDatabaseHas('audit_events', ['action' => 'user.password_reset_by_admin', 'actor_id' => $admin->id, 'target_id' => (string) $target->id]);
        $this->assertStringNotContainsString('Reset-Pass', json_encode(\DB::table('audit_events')->pluck('metadata')));
    }

    public function test_admin_password_reset_validates_and_cannot_target_oneself_or_be_done_by_others(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->viewer()->create();
        $hash = $target->password;

        foreach ([$this->pw('weak1'), ['password' => 'Correct-Horse-9999', 'password_confirmation' => 'nope-nope-nope1']] as $input) {
            try {
                $this->users->resetPassword($admin, $target, $input);
                $this->fail('invalid reset accepted');
            } catch (ValidationException) {
            }
        }
        try {
            $this->users->resetPassword($admin, $admin, $this->pw());
            $this->fail('self reset through the admin path accepted');
        } catch (ValidationException) {
        }
        foreach ([User::factory()->editor()->create(), User::factory()->viewer()->create()] as $actor) {
            try {
                $this->users->resetPassword($actor, $target, $this->pw());
                $this->fail('non-admin reset a password');
            } catch (AuthorizationException) {
            }
        }
        $this->assertSame($hash, $target->fresh()->password);
    }

    public function test_the_users_screen_creates_accounts_and_resets_passwords_for_admins_only(): void
    {
        $this->withoutVite();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(UsersIndex::class)
            ->call('openCreate')
            ->set('name', 'Screen User')->set('email', 'screen@example.test')->set('role', 'editor')
            ->set('password', 'Correct-Horse-9999')->set('password_confirmation', 'mismatch-mismatch1')
            ->call('create')->assertHasErrors(['password'])
            ->set('password_confirmation', 'Correct-Horse-9999')
            ->call('create')->assertHasNoErrors()->assertSet('dialog', null)->assertSet('password', '');
        $created = User::query()->where('email', 'screen@example.test')->firstOrFail();
        $this->assertSame(Role::Editor, $created->role);

        Livewire::actingAs($admin)->test(UsersIndex::class)
            ->call('openReset', $created->id)
            ->set('password', 'Brand-New-Pass-5555')->set('password_confirmation', 'Brand-New-Pass-5555')
            ->call('resetPassword')->assertHasNoErrors()->assertSet('dialog', null);
        $this->assertTrue(\Hash::check('Brand-New-Pass-5555', $created->fresh()->password));

        Livewire::actingAs(User::factory()->editor()->create())->test(UsersIndex::class)->assertForbidden();
    }

    public function test_a_stored_legacy_super_admin_role_reads_as_administrator_and_unknown_roles_get_nothing(): void
    {
        $legacy = User::factory()->create();
        \DB::table('users')->where('id', $legacy->id)->update(['role' => 'super_admin']);
        $odd = User::factory()->create();
        \DB::table('users')->where('id', $odd->id)->update(['role' => 'root']);

        $this->assertSame(Role::Admin, $legacy->fresh()->role);
        $this->assertTrue($legacy->fresh()->isAdmin());
        $this->assertNull($odd->fresh()->role);
        $this->assertFalse($odd->fresh()->hasPermission(Permission::ProvidersView), 'unknown roles fail closed');

        // The legacy admin counts as an administrator for the last-admin rule (no lock-out, no bypass).
        try {
            $this->users->changeRole($legacy->fresh(), $legacy->fresh(), Role::Viewer);
            $this->fail('last administrator demoted');
        } catch (ValidationException) {
        }
        // Their pages render instead of crashing.
        $this->withoutVite();
        $this->actingAs($legacy->fresh())->get('/users')->assertOk()->assertDontSee('roles.super_admin');
        $this->assertSame(['admin', 'super_admin'], Role::adminValues());
        $this->assertCount(3, Role::cases());
    }

    public function test_the_normalization_migration_maps_super_admin_to_admin_once_without_touching_credentials(): void
    {
        $legacy = User::factory()->create(['email' => 'legacy@example.test']);
        \DB::table('users')->where('id', $legacy->id)->update(['role' => 'super_admin']);
        $hash = \DB::table('users')->where('id', $legacy->id)->value('password');
        $weird = User::factory()->create();
        \DB::table('users')->where('id', $weird->id)->update(['role' => 'root']);

        $migration = require database_path('migrations/2026_11_25_000100_normalize_legacy_super_admin_role.php');
        $migration->up();
        $migration->up(); // idempotent

        $row = \DB::table('users')->where('id', $legacy->id)->first();
        $this->assertSame('admin', $row->role);
        $this->assertSame($hash, $row->password);
        $this->assertSame('root', \DB::table('users')->where('id', $weird->id)->value('role'), 'unknown roles are never guessed');
        $this->assertSame(1, \DB::table('audit_events')->where('action', 'user.role_normalized')->count());
    }

    public function test_no_email_invitation_or_verification_machinery_remains(): void
    {
        $this->assertFalse(method_exists(UserService::class, 'invite'));
        $this->assertNotContains(MustVerifyEmail::class, class_implements(User::class));
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('#forgot|reset-password|invitation|verify-email|email/verify#', $route->uri());
            $this->assertNotContains('verified', $route->gatherMiddleware());
        }
    }

    public function test_editor_and_viewer_cannot_manage_users_even_by_calling_the_service_directly(): void
    {
        $target = User::factory()->viewer()->create();

        foreach ([User::factory()->editor()->create(), User::factory()->viewer()->create()] as $actor) {
            foreach ([
                fn () => $this->users->create($actor, ['name' => 'X', 'email' => 'x@example.test', 'role' => 'admin', 'password' => 'Correct-Horse-9999', 'password_confirmation' => 'Correct-Horse-9999']),
                fn () => $this->users->changeRole($actor, $target, Role::Admin),
                fn () => $this->users->setActive($actor, $target, false),
            ] as $call) {
                try {
                    $call();
                    $this->fail('privilege escalation possible');
                } catch (AuthorizationException) {
                }
            }
        }

        $this->assertSame(Role::Viewer, $target->fresh()->role, 'no escalation happened');
        $this->assertDatabaseCount('users', 3);
    }

    public function test_the_last_active_admin_cannot_be_demoted_or_deactivated(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([fn () => $this->users->changeRole($admin, $admin, Role::Editor), fn () => $this->users->setActive($admin, $admin, false)] as $call) {
            try {
                $call();
                $this->fail('last admin protection failed');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(Role::Admin, $admin->fresh()->role);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_an_admin_can_be_demoted_when_another_active_admin_exists(): void
    {
        $a = User::factory()->admin()->create();
        $b = User::factory()->admin()->create();

        $this->users->changeRole($a, $b, Role::Viewer);
        $this->assertSame(Role::Viewer, $b->fresh()->role);

        // An INACTIVE admin does not count, so $a is now the last active admin.
        $this->expectException(ValidationException::class);
        $this->users->changeRole($a, $a, Role::Viewer);
    }

    public function test_deactivation_revokes_database_sessions_immediately(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->admin()->create();
        $target = User::factory()->editor()->create();
        \DB::table('sessions')->insert(['id' => 's1', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);

        $this->users->setActive($admin, $target, false);

        $this->assertDatabaseMissing('sessions', ['id' => 's1']);
        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_audit_log_never_stores_secrets_or_long_values(): void
    {
        $event = app(AuditLogger::class)->record('test.event', ['thing', 1], 'success', [
            'password' => 'hunter2', 'api_token' => 'abc', 'nested' => ['Authorization' => 'Bearer x', 'ok' => 'fine'],
            'long' => str_repeat('x', 500), 'count' => 3,
        ]);

        $json = json_encode($event->fresh()->metadata);
        $this->assertStringNotContainsString('hunter2', $json);
        $this->assertStringNotContainsString('Bearer', $json);
        $this->assertStringNotContainsString('api_token', $json);
        $this->assertStringContainsString('fine', $json);
        $this->assertLessThan(260, mb_strlen($event->metadata['long']));
        $this->assertSame(3, $event->metadata['count']);
    }
}
