<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    public function test_health_endpoint_reports_a_successful_boot(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_test_database_is_an_allow_listed_disposable_target(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertEmpty(config('database.connections.'.config('database.default').'.url'));
        $this->assertContains(DB::connection()->getDriverName(), ['sqlite', 'mysql', 'mariadb']);

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        } else {
            $this->assertSame('euro_assist_v2_test', DB::connection()->getDatabaseName());
        }
    }

    public function test_guard_runs_before_database_traits_and_aborts_on_an_unsafe_target(): void
    {
        config(['database.default' => 'mysql']);
        config(['database.connections.mysql.database' => 'euro_assist_v2_local']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to run tests');

        $this->guardTestDatabase($this->app);
    }

    public function test_application_refuses_to_boot_in_production_with_debug_enabled(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_DEBUG must be false');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_production_boot_is_allowed_with_debug_disabled(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false]);

        (new AppServiceProvider($this->app))->boot();
        $this->addToAssertionCount(1);
    }

    public function test_robots_txt_disallows_all_crawling_until_launch_is_approved(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Disallow: /', $robots);
        $this->assertDoesNotMatchRegularExpression('/^Disallow:\s*$/m', $robots);
    }

    public function test_route_inventory_matches_the_reviewed_allow_list(): void
    {
        $unexpected = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => ! str_starts_with($route->uri(), 'livewire')
                && ! str_starts_with($route->uri(), 'storage')
                && ! str_starts_with($route->uri(), 'build'))
            ->filter(fn ($route) => in_array('GET', $route->methods(), true)
                && ! in_array($route->uri(), ['up', '/', 'login', 'forgot-password', 'reset-password/{token}'], true)
                && ! in_array('auth', $this->middlewareOf($route), true)
                && ! str_starts_with($route->uri(), 'api/v2')
                && ! str_starts_with($route->uri(), 'invitation/'))
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $unexpected, 'Every non-public route must be behind the auth middleware.');
    }

    public function test_livewire_component_can_mount_and_handle_an_action(): void
    {
        Livewire::test(FoundationProbe::class)
            ->assertSee('Foundation probe: 0')
            ->call('increment')
            ->assertSee('Foundation probe: 1');
    }

    /** @return list<string> */
    private function middlewareOf(\Illuminate\Routing\Route $route): array
    {
        return array_map('strval', $route->gatherMiddleware());
    }
}

class FoundationProbe extends Component
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function render(): string
    {
        return '<div>Foundation probe: {{ $count }}</div>';
    }
}
