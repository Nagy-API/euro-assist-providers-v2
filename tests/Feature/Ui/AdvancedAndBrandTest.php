<?php

namespace Tests\Feature\Ui;

use App\Models\User;
use App\Services\NavigationMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdvancedAndBrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_review_is_not_in_the_main_navigation_but_on_the_advanced_page(): void
    {
        $this->withoutVite();
        $admin = User::factory()->admin()->create();
        $this->assertTrue(Route::has('migration.review.index'), 'registered in the testing environment (never in production)');

        $routes = collect(app(NavigationMenu::class)->for($admin))->flatMap(fn ($s) => $s['items'])->pluck('route');
        $this->assertNotContains('migration.review.index', $routes->all());
        $this->assertContains('settings.advanced', $routes->all());

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertDontSee('href="'.route('migration.review.index').'" @', false)
            ->assertSee(route('settings.advanced'), false);

        $this->actingAs($admin)->get(route('settings.advanced'))->assertOk()
            ->assertSee(__('ui.advanced.title'))->assertSee(route('migration.review.index'), false);
    }

    public function test_access_controls_are_unchanged(): void
    {
        $this->get(route('settings.advanced'))->assertRedirect(route('login'));
        foreach ([User::factory()->editor()->create(), User::factory()->viewer()->create()] as $user) {
            $this->actingAs($user)->get(route('settings.advanced'))->assertForbidden();
            $this->actingAs($user)->get(route('migration.review.index'))->assertForbidden();
            $names = collect(app(NavigationMenu::class)->for($user))->flatMap(fn ($s) => $s['items'])->pluck('route');
            $this->assertNotContains('settings.advanced', $names->all());
        }
        $this->actingAs(User::factory()->admin()->create())->get(route('migration.review.index'))->assertOk();
    }

    public function test_the_migration_route_is_still_absent_in_production(): void
    {
        $this->assertStringContainsString("! app()->environment('production')", (string) file_get_contents(base_path('routes/migration.php')));
    }

    public function test_the_advanced_page_renders_in_arabic_rtl_without_missing_translations(): void
    {
        $this->withoutVite();
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->withSession(['locale' => 'ar'])->get(route('settings.advanced'))->assertOk()
            ->assertSee('dir="rtl"', false)->assertSee(__('ui.advanced.title', [], 'ar'))->getContent();
        $this->assertStringNotContainsString('ui.advanced.', $html);
        $this->assertStringNotContainsString('ui.nav.', $html);
    }

    public function test_every_page_links_the_brand_icon_and_the_files_are_real_icons(): void
    {
        $this->withoutVite();
        foreach ([$this->get('/login'), $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'))] as $page) {
            $page->assertOk()->assertSee('favicon.ico', false)->assertSee('favicon-32.png', false)->assertSee('apple-touch-icon.png', false);
        }

        $ico = (string) file_get_contents(public_path('favicon.ico'));
        $this->assertGreaterThan(1000, strlen($ico), 'the Laravel placeholder was an empty file');
        $this->assertSame("\x00\x00\x01\x00", substr($ico, 0, 4), 'valid ICO header');
        foreach (['favicon-32.png' => 32, 'apple-touch-icon.png' => 180] as $file => $size) {
            $info = getimagesize(public_path($file));
            $this->assertSame([$size, $size, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);
        }
    }

    public function test_the_login_page_has_no_forgot_password_link_in_both_languages(): void
    {
        $this->withoutVite();
        $this->get('/login')->assertOk()->assertDontSee(route('login').'/forgot', false)->assertDontSee('forgot-password', false);
        $this->withSession(['locale' => 'ar'])->get('/login')->assertOk()->assertDontSee('forgot-password', false);
    }
}
