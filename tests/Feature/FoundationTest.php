<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    public function test_home_loads_the_livewire_runtime_for_its_bundled_alpine(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-update-uri=', false);
    }

    public function test_health_endpoint_reports_a_successful_boot(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_test_database_is_isolated_in_memory(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }

    public function test_livewire_component_can_mount_and_handle_an_action(): void
    {
        Livewire::test(FoundationProbe::class)
            ->assertSee('Foundation probe: 0')
            ->call('increment')
            ->assertSee('Foundation probe: 1');
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
