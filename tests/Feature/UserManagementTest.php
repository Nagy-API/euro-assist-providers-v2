<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private UserService $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = app(UserService::class);
    }

    public function test_admin_invites_a_user_who_gets_a_password_link_and_no_password_is_chosen(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();

        $user = $this->users->invite($admin, ['name' => 'New Person', 'email' => 'New@Example.test', 'role' => 'editor']);

        $this->assertSame('new@example.test', $user->email);
        $this->assertSame(Role::Editor, $user->role);
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseHas('audit_events', ['action' => 'user.invited', 'actor_id' => $admin->id]);
        $this->assertNotSame('', $user->password);
    }

    public function test_invalid_role_and_duplicate_email_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'taken@example.test']);

        $this->expectException(ValidationException::class);
        $this->users->invite($admin, ['name' => 'X', 'email' => 'taken@example.test', 'role' => 'superuser']);
    }

    public function test_editor_and_viewer_cannot_manage_users_even_by_calling_the_service_directly(): void
    {
        $target = User::factory()->viewer()->create();

        foreach ([User::factory()->editor()->create(), User::factory()->viewer()->create()] as $actor) {
            foreach ([
                fn () => $this->users->invite($actor, ['name' => 'X', 'email' => 'x@example.test', 'role' => 'admin']),
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
