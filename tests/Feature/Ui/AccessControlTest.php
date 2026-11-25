<?php

namespace Tests\Feature\Ui;

use App\Models\FeaturedProvider;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Permission matrix over EVERY route owned by the admin area: guest / viewer / editor / admin.
 * (Livewire direct-call authorization is covered in the per-feature test classes.)
 */
class AccessControlTest extends UiTestCase
{
    /** @return array<string, array{0: string, 1: array<string, int>}> */
    public static function matrix(): array
    {
        $all = ['guest' => 302, 'viewer' => 200, 'editor' => 200, 'admin' => 200];
        $editorUp = ['guest' => 302, 'viewer' => 403, 'editor' => 200, 'admin' => 200];
        $adminOnly = ['guest' => 302, 'viewer' => 403, 'editor' => 403, 'admin' => 200];

        return [
            'dashboard' => ['/dashboard', $all],
            'providers.index' => ['/providers', $all],
            'providers.create' => ['/providers/create', $editorUp],
            'providers.show' => ['/providers/{provider}', $all],
            'providers.edit' => ['/providers/{provider}/edit', $editorUp],
            'types.index' => ['/types', $adminOnly],
            'featured.index' => ['/featured', $all],
            'featured.logo' => ['/featured/{featured}/logo', $all],
            'providers.photo' => ['/providers/{provider}/photo', $all],
            'types.icon' => ['/types/{type}/icon', $all],
            'users.index' => ['/users', $adminOnly],
            'audit.index' => ['/audit', $adminOnly],
            'settings.status' => ['/settings/status', $adminOnly],
            'settings.advanced' => ['/settings/advanced', $adminOnly],
        ];
    }

    /** @param array<string, int> $expected */
    #[DataProvider('matrix')]
    public function test_route_permission_matrix(string $uri, array $expected): void
    {
        Storage::fake('local');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $type = $this->type('Icon Type', 'نوع', ['icon_path' => 'type-icons/7f1c1b8e-0a47-4c5e-9a53-2f0f0f0f0f0f.png']);
        $provider = $this->provider(['photo_path' => 'provider-photos/8f1c1b8e-0a47-4c5e-9a53-2f0f0f0f0f0f.png'], type: $type);
        $featured = FeaturedProvider::factory()->create(['logo_path' => 'featured-logos/6f1c1b8e-0a47-4c5e-9a53-2f0f0f0f0f0f.png']);
        foreach ([$featured->logo_path, $provider->photo_path, $type->icon_path] as $path) {
            Storage::disk('local')->put((string) $path, $png);
        }

        $uri = str_replace(['{provider}', '{featured}', '{type}'], [(string) $provider->id, (string) $featured->id, (string) $type->id], $uri);

        $this->get($uri)->assertRedirect(route('login'));

        foreach (['viewer', 'editor', 'admin'] as $role) {
            $this->actingAs($this->{$role}());
            $this->get($uri)->assertStatus($expected[$role], "$role requesting $uri");
            auth()->logout();
        }
    }

    public function test_every_admin_get_route_is_covered_by_the_matrix(): void
    {
        $covered = collect(self::matrix())->keys()->all();

        $actual = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true) && $r->getName() !== null)
            ->filter(fn ($r) => str_starts_with((string) $r->getActionName(), 'App\\Livewire\\')
                && ! str_starts_with((string) $r->getActionName(), 'App\\Livewire\\Transfers\\')
                && ! str_starts_with((string) $r->getActionName(), 'App\\Livewire\\Migration\\')
                || str_starts_with((string) $r->getActionName(), 'App\\Http\\Controllers\\Admin\\'))
            ->map(fn ($r) => $r->getName())
            ->all();

        foreach ($actual as $name) {
            $this->assertContains($name, $covered, "Route [$name] has no entry in the access-control matrix.");
        }
    }

    public function test_deactivated_user_is_logged_out_and_redirected(): void
    {
        $user = User::factory()->admin()->inactive()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_home_redirects_by_authentication_state(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->actingAs($this->viewer())->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_trashed_provider_is_404_for_everyone_but_admin(): void
    {
        $provider = $this->provider();
        $provider->delete();

        foreach (['viewer', 'editor'] as $role) {
            $this->actingAs($this->{$role}())->get("/providers/{$provider->id}")->assertNotFound();
            auth()->logout();
        }
        $this->actingAs($this->admin())->get("/providers/{$provider->id}")->assertOk();
    }

    public function test_numeric_route_constraint_rejects_non_numeric_ids(): void
    {
        $this->actingAs($this->admin())->get('/providers/abc')->assertNotFound();
        $this->assertSame(0, Provider::query()->count());
    }
}
