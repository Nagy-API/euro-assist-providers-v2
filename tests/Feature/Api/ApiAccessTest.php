<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\Support\ApiPrincipal;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

class ApiAccessTest extends ApiTestCase
{
    /** @return array<string, array{0: string}> */
    public static function endpoints(): array
    {
        return [
            'list' => ['/api/v2/providers'],
            'detail' => ['/api/v2/providers/1'],
            'names' => ['/api/v2/providers/names'],
            'coordinates' => ['/api/v2/providers/coordinates'],
            'distribution' => ['/api/v2/providers/distribution'],
            'types' => ['/api/v2/provider-types'],
            'featured' => ['/api/v2/featured-providers'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_anonymous_is_rejected_with_the_401_envelope_when_public_read_is_off(string $uri): void
    {
        $this->seedContractDataset();

        $response = $this->api($uri)->assertStatus(401);

        $response->assertExactJson(['error' => [
            'code' => 'unauthenticated',
            'message' => 'Authentication is required. Send a valid bearer token.',
        ]]);
        $this->assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
    }

    #[DataProvider('endpoints')]
    public function test_anonymous_is_allowed_only_when_public_read_is_enabled(string $uri): void
    {
        $this->seedContractDataset();
        config(['providers.api.public_read' => true]);

        $this->api($uri)->assertOk()->assertJsonStructure(['data', 'meta', 'links']);
    }

    #[DataProvider('endpoints')]
    public function test_every_role_with_a_valid_token_can_read(string $uri): void
    {
        $this->seedContractDataset();

        foreach (['viewer', 'editor', 'admin'] as $role) {
            $token = $this->tokenFor(User::factory()->{$role}()->create());
            $this->api($uri, $token)->assertOk();
        }
    }

    public function test_browser_session_authentication_is_not_accepted_by_the_api(): void
    {
        $this->seedContractDataset();
        $user = User::factory()->admin()->create();

        $this->actingAs($user);
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v2/providers')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_token_without_the_read_ability_is_forbidden(): void
    {
        $this->seedContractDataset();
        $token = $this->tokenFor(User::factory()->admin()->create(), ['something:else']);

        $this->api('/api/v2/providers', $token)->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    public function test_token_of_an_inactive_account_is_rejected(): void
    {
        $this->seedContractDataset();
        $user = User::factory()->admin()->create();
        $token = $this->tokenFor($user);
        $this->api('/api/v2/providers', $token)->assertOk();

        User::query()->whereKey($user->id)->update(['is_active' => false]);

        $this->api('/api/v2/providers', $token)->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_expired_and_garbage_tokens_are_rejected_even_when_public_read_is_on(): void
    {
        $this->seedContractDataset();
        $user = User::factory()->viewer()->create();
        $expired = $this->tokenFor($user, ['providers:read'], now()->subMinute());

        $this->api('/api/v2/providers', $expired)->assertStatus(401);
        $this->api('/api/v2/providers', '999|not-a-real-token')->assertStatus(401);

        config(['providers.api.public_read' => true]);
        $this->api('/api/v2/providers', $expired)->assertStatus(401);
        $this->api('/api/v2/providers', '999|not-a-real-token')->assertStatus(401);
        $this->api('/api/v2/providers')->assertOk();
    }

    public function test_a_user_without_providers_view_would_be_forbidden(): void
    {
        // Defence in depth: the gate, not only the token ability, decides.
        $this->seedContractDataset();
        $user = User::factory()->viewer()->create();
        $token = $this->tokenFor($user);
        Gate::define('providers.view', fn () => false);

        $this->api('/api/v2/providers', $token)->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    public function test_anonymous_public_callers_cannot_see_inactive_providers(): void
    {
        $this->seedContractDataset();
        config(['providers.api.public_read' => true]);

        $this->api('/api/v2/providers?is_active=false')->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
        $this->api('/api/v2/providers/4')->assertStatus(404);

        $token = $this->viewerToken();
        $this->api('/api/v2/providers?is_active=false', $token)->assertOk()->assertJsonPath('meta.total', 1);
        $this->api('/api/v2/providers/4', $token)->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_write_methods_are_not_available(): void
    {
        $this->seedContractDataset();
        $token = $this->tokenFor(User::factory()->admin()->create());

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            foreach (['/api/v2/providers', '/api/v2/providers/1', '/api/v2/provider-types', '/api/v2/featured-providers'] as $uri) {
                $this->app['auth']->forgetGuards();
                $response = $this->json($method, $uri, ['name' => 'x'], ['Authorization' => 'Bearer '.$token]);
                $this->assertContains($response->status(), [404, 405], "$method $uri must not be writable");
                $response->assertJsonStructure(['error' => ['code', 'message']]);
            }
        }

        $this->assertDatabaseCount('providers', 5);

        $writeRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v2'))
            ->flatMap(fn ($r) => $r->methods())
            ->unique()->values()->all();
        $this->assertEqualsCanonicalizing(['GET', 'HEAD'], $writeRoutes);
    }

    public function test_unknown_routes_return_the_404_envelope(): void
    {
        $token = $this->viewerToken();

        foreach (['/api/v2/nothing', '/api/v2/providers/abc', '/api/v2/providers/999', '/api/v3/providers', '/api/providers'] as $uri) {
            $this->api($uri, $token)->assertStatus(404)->assertExactJson(['error' => [
                'code' => 'not_found', 'message' => 'The requested resource was not found.',
            ]]);
        }
    }

    public function test_rate_limit_returns_the_429_envelope_with_retry_after_and_is_keyed_per_principal(): void
    {
        $this->seedContractDataset();
        config(['providers.api.rate_limit_per_minute' => 2]);
        $a = $this->tokenFor(User::factory()->viewer()->create());
        $b = $this->tokenFor(User::factory()->viewer()->create());

        $this->api('/api/v2/provider-types', $a)->assertOk()->assertHeader('X-RateLimit-Limit', '2');
        $this->api('/api/v2/provider-types', $a)->assertOk();
        $limited = $this->api('/api/v2/provider-types', $a)->assertStatus(429);

        $limited->assertJsonPath('error.code', 'rate_limited');
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));
        $this->assertSame(['error'], array_keys($limited->json()));

        // Another principal from the same IP is not throttled: the key is the authenticated user.
        $this->api('/api/v2/provider-types', $b)->assertOk();
    }

    public function test_unauthenticated_flooding_is_also_rate_limited_by_ip(): void
    {
        config(['providers.api.rate_limit_per_minute' => 2]);

        $this->api('/api/v2/providers')->assertStatus(401);
        $this->api('/api/v2/providers')->assertStatus(401);
        $this->api('/api/v2/providers')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_server_errors_never_leak_internals(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/v2/boom', fn () => throw new \RuntimeException('secret-internal-detail /var/www/app.php'));

        $response = $this->api('/api/v2/boom')->assertStatus(500);

        $response->assertExactJson(['error' => ['code' => 'server_error', 'message' => 'An unexpected error occurred.']]);
        $body = $response->getContent();
        foreach (['secret-internal-detail', 'RuntimeException', 'trace', '.php', 'vendor'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $body);
        }
    }

    public function test_token_command_requires_an_active_admin_issuer_and_prints_the_token_once_storing_only_a_hash(): void
    {
        $principal = User::factory()->viewer()->create();
        $admin = User::factory()->admin()->create();
        $editor = User::factory()->editor()->create();

        // Non-admin issuers and missing issuers are refused; nothing is created.
        $this->artisan('api:token', ['email' => $principal->email, '--issued-by' => $editor->email])->assertFailed();
        $this->artisan('api:token', ['email' => $principal->email])->assertFailed();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Inactive principals cannot receive tokens.
        $inactive = User::factory()->viewer()->inactive()->create();
        $this->artisan('api:token', ['email' => $inactive->email, '--issued-by' => $admin->email])->assertFailed();

        $this->assertSame(0, Artisan::call('api:token', ['email' => $principal->email, '--issued-by' => $admin->email, '--name' => 'ci']));
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $output = Artisan::output();
        $plain = collect(explode("\n", $output))->map(fn ($l) => trim($l))->first(fn ($l) => preg_match('/^\d+\|[A-Za-z0-9]{40,64}$/', $l));
        $this->assertNotNull($plain, 'the plain-text token is printed once');

        $row = DB::table('personal_access_tokens')->first();
        $this->assertNotNull($row);
        $secret = explode('|', (string) $plain, 2)[1];
        $this->assertSame(hash('sha256', $secret), $row->token);
        $this->assertStringNotContainsString($secret, json_encode($row));
        $this->assertSame('["providers:read"]', $row->abilities);
        $this->assertNotNull($row->expires_at);

        // The audit trail records the issuance without the secret.
        $event = AuditEvent::query()->where('action', 'api.token.issued')->firstOrFail();
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertStringNotContainsString($secret, json_encode($event->toArray()));

        // The issued token works and is read-only.
        $this->seedContractDataset();
        $this->api('/api/v2/providers', (string) $plain)->assertOk();
    }

    public function test_revoke_command_invalidates_tokens(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->viewer()->create();
        $token = $this->tokenFor($user);
        $this->seedContractDataset();
        $this->api('/api/v2/providers', $token)->assertOk();

        $this->artisan('api:token-revoke', ['email' => $user->email, '--all' => true])->assertFailed(); // no issuer
        $this->artisan('api:token-revoke', ['email' => $user->email, '--all' => true, '--issued-by' => $admin->email])->assertSuccessful();

        $this->api('/api/v2/providers', $token)->assertStatus(401);
        $this->assertSame(0, ApiPrincipal::query()->findOrFail($user->id)->tokens()->count());
    }
}
