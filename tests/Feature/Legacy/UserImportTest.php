<?php

namespace Tests\Feature\Legacy;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ADR-016: legacy staff accounts. Every account and password here is SYNTHETIC.
 */
class UserImportTest extends LegacyTestCase
{
    /** Synthetic secrets are generated per run (no credential-looking literals in the repository). */
    private string $legacyPassword = '';

    private function synthetic(): string
    {
        return 'Syn'.Str::random(16).'9';
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::connection('legacy_audit')->create('users', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('name');
            $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->string('role', 50)->default('admin');
            $t->string('remember_token', 100)->nullable();
            $t->timestamps();
        });
        // The production default (12) — the test suite itself runs with BCRYPT_ROUNDS=4.
        Hash::driver('bcrypt')->setRounds(12);
        $this->legacyPassword = $this->synthetic();
    }

    protected function tearDown(): void
    {
        // UserFactory memoizes its hashed password in a static; a hash made at cost 12 here must not
        // leak into later tests that run with the suite's BCRYPT_ROUNDS=4 (the hashed cast refuses it).
        (fn () => static::$password = null)->bindTo(null, UserFactory::class)();
        parent::tearDown();
    }

    /** @param array<string, mixed> $attrs */
    private function legacyUser(int $id, array $attrs): void
    {
        $this->legacy()->table('users')->insert($attrs + [
            'id' => $id, 'name' => 'Synthetic '.$id, 'email' => "user{$id}@legacy.example.test", 'role' => 'admin',
            'password' => password_hash($this->legacyPassword, PASSWORD_BCRYPT, ['cost' => 12]),
            'remember_token' => 'legacy-remember-token-'.$id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedLegacyUsers(): void
    {
        $this->legacyUser(1, ['email' => 'Admin@Legacy.Example.Test', 'name' => 'Admin', 'role' => 'super_admin']);
        $this->legacyUser(2, ['role' => 'editor']);
        $this->legacyUser(3, ['role' => 'manager']);
        $this->legacyUser(4, ['email' => 'not-an-email', 'role' => 'admin']);
        $this->legacyUser(5, ['email' => 'existing@legacy.example.test', 'role' => 'admin']);
    }

    public function test_activation_policy_preserves_identity_and_role_but_not_the_legacy_password(): void
    {
        $this->seedLegacyUsers();
        $existing = User::factory()->create(['email' => 'existing@legacy.example.test', 'password' => $this->synthetic()]);
        $existingHash = $existing->password;

        $this->artisan('legacy:import-users')->assertSuccessful();

        $admin = User::query()->where('email', 'admin@legacy.example.test')->firstOrFail();
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertSame('Admin', $admin->name);
        $this->assertFalse(Hash::check($this->legacyPassword, $admin->password), 'activation policy must not keep the legacy password');
        $this->assertFalse(Auth::guard('web')->validate(['email' => 'admin@legacy.example.test', 'password' => $this->legacyPassword]));
        $this->assertNull($admin->remember_token, 'legacy remember tokens are never copied');
        $this->assertSame(User::ACTIVATION_REQUIRED_PASSWORD, $admin->getAttributes()['password'], 'the fixed activation placeholder, never a random or legacy hash');
        $this->assertTrue($admin->requiresActivation());

        $this->assertSame(Role::Editor, User::query()->where('email', 'user2@legacy.example.test')->firstOrFail()->role);
        $this->assertFalse(User::query()->where('email', 'user3@legacy.example.test')->exists(), 'unknown role is held');
        $this->assertFalse(User::query()->where('email', 'not-an-email')->exists(), 'invalid e-mail is held');
        $this->assertSame($existingHash, $existing->fresh()->password, 'an existing V2 account is never touched');

        $events = AuditEvent::query()->where('action', 'user.migrated_from_legacy')->get();
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertStringNotContainsString('$2y$', json_encode($event->metadata));
            $this->assertSame('activation', $event->metadata['access_policy']);
        }
    }

    public function test_preserve_hash_policy_keeps_the_legacy_password_working(): void
    {
        $this->legacyUser(1, ['email' => 'admin@legacy.example.test', 'role' => 'super_admin']);
        $legacyHash = $this->legacy()->table('users')->where('id', 1)->value('password');

        $this->artisan('legacy:import-users', ['--passwords' => 'preserve-hash'])->assertSuccessful();

        $admin = User::query()->where('email', 'admin@legacy.example.test')->firstOrFail();
        $this->assertSame($legacyHash, $admin->password, 'the hash is copied unchanged');
        $this->assertTrue(Auth::guard('web')->validate(['email' => 'admin@legacy.example.test', 'password' => $this->legacyPassword]));
        $this->post(route('login.store'), ['email' => 'admin@legacy.example.test', 'password' => $this->legacyPassword])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_preserve_hash_holds_hashes_the_v2_hasher_cannot_verify(): void
    {
        Hash::driver('bcrypt')->setRounds(10); // a legacy cost (12) above the configured rounds is refused by Laravel
        $this->legacyUser(1, ['email' => 'admin@legacy.example.test']);
        $this->legacyUser(2, ['email' => 'md5@legacy.example.test', 'password' => md5('x')]);

        $this->artisan('legacy:import-users', ['--passwords' => 'preserve-hash'])->assertSuccessful();

        $this->assertSame(0, User::query()->count());
    }

    public function test_dry_run_writes_nothing_and_unknown_policy_is_refused(): void
    {
        $this->seedLegacyUsers();

        $this->artisan('legacy:import-users', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, AuditEvent::query()->count());

        $this->artisan('legacy:import-users', ['--passwords' => 'plaintext'])->assertFailed();
    }

    public function test_import_is_idempotent(): void
    {
        $this->seedLegacyUsers();
        $this->artisan('legacy:import-users')->assertSuccessful();
        $count = User::query()->count();
        $this->artisan('legacy:import-users')->assertSuccessful();
        $this->assertSame($count, User::query()->count());
    }

    public function test_import_is_refused_in_production(): void
    {
        $this->seedLegacyUsers();
        $this->app['env'] = 'production';
        $this->artisan('legacy:import-users')->assertFailed();
        $this->app['env'] = 'testing';
        $this->assertSame(0, User::query()->count());
    }

    public function test_activation_with_set_password_then_login(): void
    {
        config(['session.driver' => 'database']);
        $this->legacyUser(1, ['email' => 'admin@legacy.example.test', 'role' => 'super_admin']);
        $this->artisan('legacy:import-users')->assertSuccessful();
        $admin = User::query()->where('email', 'admin@legacy.example.test')->firstOrFail();
        DB::table('sessions')->insert(['id' => 'stale-session', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()]);

        $new = $this->synthetic();
        $this->artisan('users:set-password', ['email' => 'Admin@legacy.example.test'])
            ->expectsQuestion('New password (min 12 characters, hidden)', $new)
            ->expectsQuestion('Repeat the new password (hidden)', $new)
            ->assertSuccessful();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $admin->id)->count(), 'existing sessions are ended');
        $this->assertTrue(AuditEvent::query()->where('action', 'user.password_set_by_operator')->exists());
        $this->assertFalse($admin->fresh()->requiresActivation(), 'activation replaces the placeholder');
        $this->post(route('login.store'), ['email' => 'admin@legacy.example.test', 'password' => $new])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_set_password_refuses_unknown_accounts_and_mismatched_entries(): void
    {
        $this->artisan('users:set-password', ['email' => 'nobody@legacy.example.test'])->assertFailed();

        $user = User::factory()->create(['email' => 'someone@legacy.example.test', 'password' => $this->synthetic()]);
        $hash = $user->password;
        $this->artisan('users:set-password', ['email' => 'someone@legacy.example.test'])
            ->expectsQuestion('New password (min 12 characters, hidden)', $this->synthetic())
            ->expectsQuestion('Repeat the new password (hidden)', $this->synthetic())
            ->assertFailed();
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_activation_placeholder_never_verifies_and_login_is_refused_cleanly(): void
    {
        $placeholder = User::ACTIVATION_REQUIRED_PASSWORD;
        $this->assertSame('bcrypt', Hash::info($placeholder)['algoName']);
        $this->assertSame(60, strlen($placeholder));
        foreach (['', 'password', 'admin', $placeholder, $this->synthetic(), 'EuroAssistV2ActivationRequired'] as $candidate) {
            $this->assertFalse(password_verify($candidate, $placeholder));
            $this->assertFalse(Hash::check($candidate, $placeholder));
        }

        Hash::driver('bcrypt')->setRounds(4); // the placeholder must not depend on BCRYPT_ROUNDS
        $this->legacyUser(1, ['email' => 'admin@legacy.example.test', 'role' => 'super_admin']);
        $this->artisan('legacy:import-users')->assertSuccessful();

        $this->post(route('login.store'), ['email' => 'admin@legacy.example.test', 'password' => 'password'])
            ->assertRedirect()->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
