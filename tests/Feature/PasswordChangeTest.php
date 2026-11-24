<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Livewire\Users\Index as UsersIndex;
use App\Models\AuditEvent;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\TestCase;

/** Forced replacement of a temporary password (ADR-019), synthetic accounts only. */
class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private const TEMP = 'Temp0rary-Passw0rd-For-Tests';

    private const NEW = 'Brand-New-Passw0rd-Chosen';

    private function flagged(array $attributes = []): User
    {
        return User::factory()->admin()->create($attributes + ['password' => self::TEMP, 'must_change_password' => true]);
    }

    public function test_a_flagged_account_can_open_nothing_but_the_change_page(): void
    {
        $user = $this->flagged();

        foreach (['dashboard', 'providers.index', 'providers.create', 'featured.index', 'imports.index', 'exports.index', 'users.index', 'audit.index'] as $route) {
            $this->actingAs($user)->get(route($route))->assertRedirect(route('password.change'));
        }
        $this->actingAs($user)->get('/')->assertRedirect(route('dashboard'));
        $this->actingAs($user)->get(route('password.change'))->assertOk()->assertSee(__('auth.change_required'));
    }

    public function test_signing_in_with_the_temporary_password_leads_straight_to_the_change_page(): void
    {
        $user = $this->flagged();

        $this->post('/login', ['email' => $user->email, 'password' => self::TEMP])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertRedirect(route('password.change'));
    }

    public function test_an_account_without_the_flag_is_not_affected(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->actingAs($user)->get(route('password.change'))->assertRedirect(route('dashboard'));
        $this->actingAs($user)->post(route('password.change.update'), ['current_password' => UserFactory::TEST_PASSWORD, 'password' => self::NEW, 'password_confirmation' => self::NEW])
            ->assertRedirect(route('dashboard'));
        $this->assertTrue(Hash::check(UserFactory::TEST_PASSWORD, $user->fresh()->password), 'the endpoint changes nothing for an unflagged account');
    }

    public function test_the_flagged_account_can_still_sign_out_and_switch_language(): void
    {
        $user = $this->flagged();

        $this->actingAs($user)->post(route('locale.update', 'ar'))->assertRedirect();
        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_the_new_password_is_validated_and_the_temporary_one_must_be_correct(): void
    {
        $user = $this->flagged();
        $post = fn (array $data) => $this->actingAs($user)->from(route('password.change'))->post(route('password.change.update'), $data);

        $post(['current_password' => 'not-the-temporary-password', 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertSessionHasErrors('current_password');
        $post(['current_password' => self::TEMP, 'password' => self::TEMP, 'password_confirmation' => self::TEMP])->assertSessionHasErrors('password');
        $post(['current_password' => self::TEMP, 'password' => 'short1', 'password_confirmation' => 'short1'])->assertSessionHasErrors('password');
        $post(['current_password' => self::TEMP, 'password' => 'onlyletterslongenough', 'password_confirmation' => 'onlyletterslongenough'])->assertSessionHasErrors('password');
        $post(['current_password' => self::TEMP, 'password' => self::NEW, 'password_confirmation' => 'something-else-1234'])->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check(self::TEMP, $user->fresh()->password));
        $this->assertSame(1, AuditEvent::query()->where('action', 'auth.password_change_failed')->count());
    }

    public function test_a_valid_change_clears_the_flag_replaces_the_password_and_opens_the_application(): void
    {
        $user = $this->flagged();

        $this->actingAs($user)->post(route('password.change.update'), ['current_password' => self::TEMP, 'password' => self::NEW, 'password_confirmation' => self::NEW])
            ->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();

        $fresh = $user->fresh();
        $this->assertFalse($fresh->must_change_password);
        $this->assertTrue(Hash::check(self::NEW, $fresh->password));
        $this->assertFalse(Hash::check(self::TEMP, $fresh->password), 'the temporary password stops working');
        $this->assertSame(1, AuditEvent::query()->where('action', 'auth.password_changed')->count());
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('password.change'))->assertRedirect(route('dashboard'));
    }

    public function test_after_the_change_only_the_new_password_signs_in(): void
    {
        $user = $this->flagged();
        $this->actingAs($user)->post(route('password.change.update'), ['current_password' => self::TEMP, 'password' => self::NEW, 'password_confirmation' => self::NEW]);
        $this->post('/logout');

        $this->post('/login', ['email' => $user->email, 'password' => self::TEMP])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => self::NEW])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_livewire_and_json_requests_of_a_flagged_account_are_refused(): void
    {
        $user = $this->flagged();
        $middleware = new EnsurePasswordIsChanged;
        $next = fn () => response('reached');

        foreach ([['X-Livewire' => 'true'], ['Accept' => 'application/json']] as $headers) {
            $request = Request::create('/livewire/update', 'POST');
            foreach ($headers as $name => $value) {
                $request->headers->set($name, $value);
            }
            $request->setUserResolver(fn () => $user);
            $this->assertSame(403, $middleware->handle($request, $next)->getStatusCode());
        }

        $request = Request::create('/dashboard', 'GET');
        $request->setUserResolver(fn () => $user->forceFill(['must_change_password' => false]));
        $this->assertSame('reached', $middleware->handle($request, $next)->getContent());

        $this->assertContains(EnsurePasswordIsChanged::class, Livewire::getPersistentMiddleware());
    }

    public function test_a_real_livewire_update_request_of_a_flagged_account_is_refused(): void
    {
        $admin = User::factory()->admin()->create(['password' => self::TEMP]);
        $this->withoutVite();
        $html = $this->actingAs($admin)->get(route('users.index'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/wire:snapshot="([^"]+)"/', (string) $html, $m));
        $payload = json_encode(['_token' => 'x', 'components' => [['snapshot' => html_entity_decode($m[1], ENT_QUOTES), 'updates' => new \stdClass, 'calls' => []]]]);
        $send = fn (User $u) => $this->actingAs($u)->call(
            'POST',
            app(HandleRequests::class)->getUpdateUri(),
            [], [], [],
            $this->transformHeadersToServerVars(['X-Livewire' => 'true', 'Content-Type' => 'application/json', 'Accept' => 'application/json']),
            (string) $payload,
        );

        $this->assertSame(200, $send($admin)->getStatusCode(), 'control: an unflagged account is served');

        $admin->forceFill(['must_change_password' => true])->save();
        \Livewire\trigger('flush-state'); // the persistent-middleware route cache is static inside one test process
        $response = $send($admin->fresh());
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Password change required', (string) $response->getContent());
    }

    public function test_every_authenticated_web_route_enforces_the_password_change(): void
    {
        $open = ['logout', 'password.change', 'password.change.update'];
        $checked = 0;
        foreach (app('router')->getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            $authenticated = in_array('auth', $middleware, true) || in_array('active', $middleware, true);
            if (! $authenticated || in_array($route->getName(), $open, true)) {
                continue;
            }
            $checked++;
            $this->assertContains('password.fresh', $middleware, 'route without the password-change guard: '.($route->getName() ?? $route->uri()));
        }
        $this->assertGreaterThan(25, $checked, 'the enumeration must actually cover the application routes');
    }

    public function test_the_change_ends_other_sessions_and_rotates_the_remember_token(): void
    {
        $user = $this->flagged();
        $before = $user->fresh()->remember_token;

        $this->post('/login', ['email' => $user->email, 'password' => self::TEMP, 'remember' => '1'])->assertRedirect(route('dashboard'));
        $this->post(route('password.change.update'), ['current_password' => self::TEMP, 'password' => self::NEW, 'password_confirmation' => self::NEW])
            ->assertRedirect(route('dashboard'));

        $this->assertNotSame($before, $user->fresh()->remember_token);
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_a_password_reset_or_the_operator_command_also_clears_the_flag(): void
    {
        $user = $this->flagged(['email' => 'reset@example.test']);
        $token = Password::createToken($user);
        $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertRedirect(route('login'));
        $this->assertFalse($user->fresh()->must_change_password);

        $other = $this->flagged(['email' => 'operator@example.test']);
        $this->artisan('users:set-password', ['email' => $other->email])
            ->expectsQuestion('New password (min 12 characters, hidden)', self::NEW)
            ->expectsQuestion('Repeat the new password (hidden)', self::NEW)
            ->assertSuccessful();
        $this->assertFalse($other->fresh()->must_change_password);
    }

    public function test_the_users_screen_marks_accounts_that_hold_a_temporary_password(): void
    {
        $this->flagged(['email' => 'temp@example.test', 'name' => 'Temp Holder']);
        $admin = User::factory()->admin()->create();
        $this->withoutVite();

        Livewire::actingAs($admin)->test(UsersIndex::class)->assertSee(__('ui.users.password_change_required'));
    }

    public function test_the_change_page_renders_in_both_languages(): void
    {
        $user = $this->flagged();
        $this->withoutVite();

        $this->actingAs($user)->get(route('password.change'))->assertOk()->assertSee(__('auth.change_title'))->assertSee('name="current_password"', false);
        app()->setLocale('ar');
        $this->actingAs($user)->withSession(['locale' => 'ar'])->get(route('password.change'))->assertOk()->assertSee('dir="rtl"', false);
    }
}
