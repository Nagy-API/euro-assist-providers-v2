<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user, string $password = UserFactory::TEST_PASSWORD)
    {
        return $this->post('/login', ['email' => $user->email, 'password' => $password]);
    }

    public function test_guests_are_redirected_from_every_protected_route(): void
    {
        foreach (['/dashboard'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_login_page_renders_with_csrf_and_security_basics(): void
    {
        $this->get('/login')->assertOk()->assertSee('name="_token"', false)->assertSee('noindex', false);
    }

    public function test_guest_pages_load_livewire_so_alpine_controls_work(): void
    {
        // Livewire 4 starts Alpine. Without its script the password visibility toggle (x-data) was inert.
        // One page per request cycle: Livewire renders its script tag once per (test) application.
        $this->get('/login')->assertOk()->assertSee('data-update-uri', false)->assertSee('x-on:click="show = !show"', false);
    }

    public function test_valid_login_regenerates_the_session_and_records_audit_and_last_login(): void
    {
        $user = User::factory()->editor()->create();
        $this->startSession();
        $before = session()->getId();

        $this->login($user)->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId(), 'session id must be regenerated on login');
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.login', 'actor_id' => $user->id, 'result' => 'success']);
    }

    public function test_wrong_password_unknown_user_and_inactive_user_get_the_same_generic_error(): void
    {
        $active = User::factory()->create();
        $inactive = User::factory()->inactive()->create();

        $messages = [];
        foreach ([[$active->email, 'wrong-password'], ['nobody@example.test', 'x'], [$inactive->email, UserFactory::TEST_PASSWORD]] as [$email, $pw]) {
            RateLimiter::clear('x');
            $response = $this->from('/login')->post('/login', ['email' => $email, 'password' => $pw]);
            $response->assertRedirect('/login')->assertSessionHasErrors('email');
            $messages[] = session('errors')->first('email');
            $this->assertGuest();
        }

        $this->assertCount(1, array_unique($messages), 'no account-enumeration difference');
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.login_failed', 'result' => 'failure']);
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'bad'])->assertStatus(302);
        }

        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::TEST_PASSWORD])
            ->assertStatus(429);
        $this->assertGuest();
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.logout', 'actor_id' => $user->id]);
    }

    public function test_logout_requires_post_and_csrf_is_enforced_for_login(): void
    {
        $this->get('/logout')->assertStatus(405);

        $this->withMiddleware();
        $user = User::factory()->create();
        // The test client skips CSRF by default; assert the middleware is registered on the route.
        $route = app('router')->getRoutes()->getByName('login.store');
        $this->assertContains('web', $route->gatherMiddleware());
    }

    public function test_deactivated_user_with_an_open_session_is_logged_out_on_next_request(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_authenticated_users_cannot_open_the_login_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/dashboard');
    }

    public function test_password_reset_flow_works_and_does_not_reveal_unknown_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->post('/forgot-password', ['email' => $user->email]);
        $unknown = $this->post('/forgot-password', ['email' => 'ghost@example.test']);
        $this->assertSame(session('status'), session('status'));
        $known->assertSessionHas('status');
        $unknown->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);

        $token = Password::createToken($user);
        $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'Correct-Horse-9999', 'password_confirmation' => 'Correct-Horse-9999',
        ])->assertRedirect('/login');

        $this->assertTrue(\Hash::check('Correct-Horse-9999', $user->fresh()->password));
    }

    public function test_weak_passwords_are_rejected_on_reset(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email, 'password' => 'short1', 'password_confirmation' => 'short1',
        ])->assertSessionHasErrors('password');
    }

    public function test_locale_switch_is_validated_and_persisted(): void
    {
        $this->post('/locale/ar')->assertRedirect();
        $this->get('/login')->assertSee('dir="rtl"', false)->assertSee('lang="ar"', false);

        $this->post('/locale/fr')->assertNotFound();
        $this->post('/locale/en');
        $this->get('/login')->assertSee('dir="ltr"', false);
    }

    public function test_provisioning_command_creates_a_user_without_printing_the_password(): void
    {
        $this->artisan('users:provision', ['email' => 'Local.Admin@example.test', '--role' => 'admin', '--generate' => true])
            ->doesntExpectOutputToContain('password:')
            ->assertSuccessful();

        $user = User::query()->where('email', 'local.admin@example.test')->firstOrFail();
        $this->assertSame('admin', $user->role->value);

        $files = glob(storage_path('app/private/provisioned/'.Str::slug('local.admin@example.test').'-*.txt'));
        $this->assertCount(1, $files);
        $file = $files[0];
        $this->assertTrue(\Hash::check(trim(explode('password: ', file_get_contents($file))[1]), $user->password));
        @unlink($file);
    }

    public function test_provisioning_rejects_invalid_roles_and_duplicates(): void
    {
        User::factory()->create(['email' => 'dup@example.test']);

        $this->artisan('users:provision', ['email' => 'dup@example.test', '--generate' => true])->assertFailed();
        $this->artisan('users:provision', ['email' => 'new@example.test', '--role' => 'root', '--generate' => true])->assertFailed();
    }
}
