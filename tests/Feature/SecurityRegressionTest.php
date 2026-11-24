<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\Api\Support\ApiPrincipal;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\AuditLogger;
use App\Services\ProviderService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** Regression tests for the findings of independent security review R1. */
class SecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ProviderService
    {
        return app(ProviderService::class);
    }

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return array_replace([
            'provider_type_id' => ProviderType::factory()->create()->id,
            'translations' => ['en' => ['name' => 'Sec Clinic', 'address' => '1 Safe St']],
        ], $override);
    }

    // I-1 -------------------------------------------------------------------------------
    public function test_production_pins_generated_links_to_the_configured_https_url(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false, 'app.url' => 'https://providers.example.test', 'session.secure' => true]);

        (new AppServiceProvider($this->app))->boot();

        $link = url('/login');
        $this->assertStringStartsWith('https://providers.example.test/', $link);

        URL::forceRootUrl(null);
        URL::forceScheme(null);
    }

    public function test_production_refuses_a_non_https_app_url(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false, 'app.url' => 'http://providers.example.test']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_URL must be an https');

        (new AppServiceProvider($this->app))->boot();
    }

    // I-2 -------------------------------------------------------------------------------
    public function test_dangerous_url_schemes_and_path_traversal_are_rejected(): void
    {
        $editor = User::factory()->editor()->create();

        foreach ([
            ['website' => 'javascript:alert(document.cookie)'],
            ['website' => 'data:text/html;base64,PHNjcmlwdD4='],
            ['website' => 'JaVaScRiPt:alert(1)'],
            ['website' => 'https://exa mple.com'],
            ['website' => 'https://x.test/"onmouseover=alert(1)'],
            ['map_url' => 'javascript:alert(1)'],
            ['map_url' => 'data:text/html,<b>x</b>'],
            ['map_url' => 'file:///etc/passwd'],
            ['map_url' => 'https://maps.example.test/a b'],
            ['map_url' => 'https://maps.example.test/"x'],
            ['logo_path' => '../../../.env'],
            ['logo_path' => '/etc/passwd'],
            ['logo_path' => 'a/../../b.png'],
            ['logo_path' => 'javascript:alert(1)'],
        ] as $bad) {
            try {
                $this->service()->create($editor, $this->payload($bad));
                $this->fail('Accepted unsafe value: '.json_encode($bad));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey(array_key_first($bad), $e->errors());
            }
        }
        $this->assertDatabaseCount('providers', 0);
    }

    public function test_legitimate_urls_and_logo_paths_are_still_accepted(): void
    {
        $provider = $this->service()->create(User::factory()->editor()->create(), $this->payload([
            'website' => 'www.example.test',
            'map_url' => 'https://maps.example.test/?q=30.0444,31.2357',
            'logo_path' => 'uploads/logos/brand-1.png',
        ]));

        $this->assertSame('www.example.test', $provider->website);

        $second = $this->service()->create(User::factory()->editor()->create(), $this->payload([
            'website' => 'example.test:8080/path', 'logo_path' => 'https://cdn.example.test/logo.png',
        ]));
        $this->assertSame('example.test:8080/path', $second->website);

        // Real legacy map links: an apostrophe (DMS coordinates) and a scheme-less short link are
        // preserved exactly; neither can carry a scheme.
        $third = $this->service()->create(User::factory()->editor()->create(), $this->payload([
            'map_url' => "https://maps.example.test/place/30%C2%B002'40.0N",
        ]));
        $this->assertSame("https://maps.example.test/place/30%C2%B002'40.0N", $third->map_url);
        $fourth = $this->service()->create(User::factory()->editor()->create(), $this->payload(['map_url' => 'maps.example.test/abc']));
        $this->assertSame('maps.example.test/abc', $fourth->map_url);
    }

    // I-3 -------------------------------------------------------------------------------
    public function test_a_stale_revision_is_rejected_instead_of_silently_overwriting(): void
    {
        $editor = User::factory()->editor()->create();
        $provider = $this->service()->create($editor, $this->payload());
        $seen = $provider->revision;

        $this->service()->update($editor, $provider, ['phone' => '111']);          // first writer wins
        $this->assertSame($seen + 1, $provider->fresh()->revision);

        try {
            $this->service()->update($editor, $provider, ['phone' => '222', 'expected_revision' => $seen]);
            $this->fail('stale write accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('revision', $e->errors());
        }
        $this->assertSame('111', $provider->fresh()->phone);

        $ok = $this->service()->update($editor, $provider, ['phone' => '333', 'expected_revision' => $seen + 1]);
        $this->assertSame('333', $ok->phone);
    }

    // M-5 / M-6 -------------------------------------------------------------------------
    public function test_blank_type_on_update_preserves_and_deleted_providers_cannot_be_edited(): void
    {
        $admin = User::factory()->admin()->create();
        $provider = $this->service()->create($admin, $this->payload());
        $type = $provider->provider_type_id;

        $this->assertSame($type, $this->service()->update($admin, $provider, ['provider_type_id' => ''])->provider_type_id);

        $provider->delete();
        $this->expectException(ValidationException::class);
        $this->service()->update($admin, $provider->fresh() ?? Provider::withTrashed()->find($provider->id), ['phone' => '1']);
    }

    // M-8 -------------------------------------------------------------------------------
    public function test_bulk_ids_are_validated_and_capped(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([[], [true], ['abc'], [1.5], range(1, 1001), [['x']]] as $ids) {
            try {
                $this->service()->bulkDelete($admin, $ids);
                $this->fail('accepted ids '.json_encode($ids));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // M-1 -------------------------------------------------------------------------------
    public function test_malformed_login_input_never_causes_a_server_error(): void
    {
        $this->post('/login', ['email' => ['x'], 'password' => 'p'])->assertStatus(302);
        $this->post('/login', ['email' => 'a@b.test', 'password' => ['p']])->assertStatus(302);
    }

    // M-2 -------------------------------------------------------------------------------
    public function test_admin_password_reset_ends_other_sessions(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        app(UserService::class)->resetPassword($admin, $user, ['password' => 'Another-Strong-1234', 'password_confirmation' => 'Another-Strong-1234']);

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
    }

    // Review R2 / L2: credentials changes close every way in, API tokens included ------------
    public function test_admin_password_reset_and_deactivation_revoke_api_tokens(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->viewer()->create();
        $principal = fn () => ApiPrincipal::query()->findOrFail($user->id);

        $principal()->createToken('t1', ['providers:read']);
        app(UserService::class)->resetPassword($admin, $user, ['password' => 'Another-Strong-1234', 'password_confirmation' => 'Another-Strong-1234']);
        $this->assertSame(0, $principal()->tokens()->count(), 'password reset must revoke API tokens');

        $plain = $principal()->createToken('t2', ['providers:read'])->plainTextToken;
        app(UserService::class)->setActive($admin, $user, false);
        app(UserService::class)->setActive($admin, $user, true);
        $this->assertSame(0, $principal()->tokens()->count(), 'deactivation must revoke API tokens');
        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v2/providers')->assertStatus(401);
    }

    // Review R2 / L1: failed bearer tokens are throttled per IP before authentication ----------
    public function test_invalid_api_tokens_are_rate_limited_per_ip(): void
    {
        config(['providers.api.ip_rate_limit_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->withHeader('Authorization', 'Bearer 1|guess'.$i)->getJson('/api/v2/providers')->assertStatus(401);
        }
        $this->withHeader('Authorization', 'Bearer 1|guess-again')->getJson('/api/v2/providers')->assertStatus(429);
    }

    // Review R2 / L4: private files are never served over HTTP ----------------------------------
    public function test_private_disk_is_not_served(): void
    {
        $this->assertFalse((bool) config('filesystems.disks.local.serve'));
        $this->assertFalse(Route::has('storage.local'));
        $this->assertGreaterThan(3600, (int) config('providers.imports.lock_seconds'), 'import lock must outlive the job timeout (L3)');
    }

    // M-3 -------------------------------------------------------------------------------
    public function test_last_admin_rule_uses_fresh_database_state_not_a_stale_model(): void
    {
        $admin = User::factory()->admin()->create();
        $stale = User::query()->findOrFail($admin->id);
        $stale->role = Role::Editor; // in-memory model lies about being an editor

        $this->expectException(ValidationException::class);
        app(UserService::class)->changeRole($admin, $stale, Role::Viewer);
    }

    public function test_create_with_mixed_case_duplicate_is_a_validation_error(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'person@example.test']);

        $this->expectException(ValidationException::class);
        app(UserService::class)->create($admin, ['name' => 'P', 'email' => 'Person@Example.TEST', 'role' => 'viewer', 'password' => 'Correct-Horse-9999', 'password_confirmation' => 'Correct-Horse-9999']);
    }

    // M-4 -------------------------------------------------------------------------------
    public function test_bulk_token_cannot_be_claimed_twice(): void
    {
        $editor = User::factory()->editor()->create();
        $provider = Provider::factory()->bilingual()->create();
        $preview = $this->service()->previewBulk($editor, [$provider->id], ['is_active' => false]);

        cache()->add('bulk-provider-update:'.$editor->id.':'.$preview['token'].':used', 1, 60); // someone else claimed it

        $this->expectException(ValidationException::class);
        $this->service()->applyBulk($editor, $preview['token']);
    }

    // M-7 -------------------------------------------------------------------------------
    public function test_audit_metadata_drops_credential_like_keys_and_values(): void
    {
        $event = app(AuditLogger::class)->record('t.x', null, 'success', [
            'apikey' => 'k', 'private_key' => 'k', 'otp' => '123456', 'credential' => 'c', 'passwd' => 'p',
            'note' => 'Bearer abcdef', 'dsn' => 'mysql://user:hunter2@db/x', 'safe' => 'ok',
        ]);

        $this->assertSame(['safe' => 'ok'], $event->fresh()->metadata);
    }

    // M-14 ------------------------------------------------------------------------------
    public function test_security_headers_are_present(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    // M-16 ------------------------------------------------------------------------------
    public function test_livewire_requests_re_check_that_the_account_is_still_active(): void
    {
        $persistent = Livewire::getPersistentMiddleware();

        $this->assertContains(EnsureUserIsActive::class, $persistent);
    }

    // Host/proxy trust must be read from config at request time (config:cache safe) -----------
    public function test_trusted_host_is_exactly_the_app_url_host_and_read_from_config(): void
    {
        config(['app.url' => 'https://providers.example.test']);

        $hosts = (new class($this->app) extends TrustHosts
        {
            /** @return array<int, string|null> */
            public function exposedHosts(): array
            {
                return $this->hosts();
            }
        })->exposedHosts();

        $this->assertSame(['^providers\.example\.test$'], $hosts);
        $this->assertSame(1, preg_match('{'.$hosts[0].'}i', 'providers.example.test'));
        $this->assertSame(0, preg_match('{'.$hosts[0].'}i', 'evil.providers.example.test'));
        $this->assertSame(0, preg_match('{'.$hosts[0].'}i', 'providers.example.test.evil.io'));
    }

    public function test_forwarded_client_ip_is_only_honoured_from_a_configured_proxy(): void
    {
        $probe = fn () => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->withHeader('X-Forwarded-For', '203.0.113.9')
            ->get('/login');

        $probe()->assertOk();
        $this->assertSame('10.0.0.5', request()->ip(), 'no proxy configured: forwarded header ignored');

        config(['app.trusted_proxies' => '10.0.0.5']);
        (new AppServiceProvider($this->app))->boot();
        $probe()->assertOk();
        $this->assertSame('203.0.113.9', request()->ip());

        TrustProxies::flushState();
    }

    // Review R3 / L2: production refuses insecure cookies and a wildcard proxy list ------------
    public function test_production_refuses_insecure_session_cookies_and_wildcard_proxies(): void
    {
        $this->app['env'] = 'production';
        foreach ([
            [['session.secure' => null], 'SESSION_SECURE_COOKIE'],
            [['session.secure' => true, 'app.trusted_proxies' => '*'], 'TRUSTED_PROXIES'],
        ] as [$config, $needle]) {
            config(['app.debug' => false, 'app.url' => 'https://providers.example.test', 'app.trusted_proxies' => ''] + $config);
            try {
                (new AppServiceProvider($this->app))->boot();
                $this->fail("production must refuse: {$needle}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        }
        URL::forceRootUrl(null);
        URL::forceScheme(null);
    }

    // Review R3 / L3: one account is throttled across many IP addresses ------------------------
    public function test_login_attempts_for_one_account_are_limited_across_ips(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 30; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.($i + 1)])
                ->post('/login', ['email' => $user->email, 'password' => 'wrong-password-'.$i])
                ->assertStatus(302);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
            ->post('/login', ['email' => $user->email, 'password' => 'another-guess'])
            ->assertStatus(429);
    }

    // Review R3 / L4: CSP forbids plugins -----------------------------------------------------
    public function test_csp_disallows_object_embeds(): void
    {
        $this->assertStringContainsString("object-src 'none'", (string) $this->get('/login')->headers->get('Content-Security-Policy'));
    }

    // Review R3 / info: the synthetic benchmark never runs outside local/testing ----------------
    public function test_benchmark_refuses_staging(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');
        $this->artisan('transfers:benchmark', ['rows' => 1])->expectsOutputToContain('LOCAL-ONLY')->assertFailed();
    }
}
