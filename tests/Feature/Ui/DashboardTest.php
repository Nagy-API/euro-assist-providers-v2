<?php

namespace Tests\Feature\Ui;

use App\Livewire\Dashboard;
use App\Models\FeaturedProvider;
use App\Models\Provider;
use App\Services\DashboardStats;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class DashboardTest extends UiTestCase
{
    public function test_counters_are_computed_from_canonical_non_deleted_records_only(): void
    {
        $clinic = $this->type('Clinic', 'عيادة');
        // bilingual, active, has city + phone
        $this->provider(['phone' => '0123'], type: $clinic);
        // english only, inactive, no city, no phone
        $enOnly = Provider::factory()->create(['provider_type_id' => $clinic->id, 'is_active' => false, 'phone' => null]);
        $enOnly->translations()->create(['locale' => 'en', 'name' => 'E', 'address' => 'A']);
        // arabic only, active, empty-string phone counts as missing
        $arOnly = Provider::factory()->create(['provider_type_id' => $clinic->id, 'phone' => '']);
        $arOnly->translations()->create(['locale' => 'ar', 'name' => 'ع', 'address' => 'ع', 'city' => 'القاهرة']);
        // soft-deleted: must not be counted in any live figure
        $this->provider(['phone' => null], type: $clinic)->delete();
        FeaturedProvider::factory()->create();
        FeaturedProvider::factory()->create(['is_active' => false]);

        $c = app(DashboardStats::class)->counters(includeDeleted: true);

        $this->assertSame(3, $c['total']);
        $this->assertSame(2, $c['active']);
        $this->assertSame(1, $c['inactive']);
        $this->assertSame(1, $c['deleted']);
        $this->assertSame(2, $c['missing_phone']);  // null + empty string
        $this->assertSame(1, $c['missing_city']);   // only the English-only record lacks any city
        $this->assertSame(['ar' => 2, 'en' => 2, 'both' => 1, 'only_ar' => 1, 'only_en' => 1, 'none' => 0], $c['translations']);
        $this->assertSame(['total' => 2, 'active' => 1], $c['featured']);
    }

    public function test_deleted_count_is_visible_only_to_roles_that_may_restore(): void
    {
        $this->provider()->delete();

        $this->assertNull(app(DashboardStats::class)->counters(includeDeleted: false)['deleted']);

        Livewire::actingAs($this->admin())->test(Dashboard::class)->assertSee(__('ui.dashboard.deleted'));
        // Editors cannot delete/restore (ADR-010), so they do not see deleted records or counts either.
        Livewire::actingAs($this->editor())->test(Dashboard::class)->assertDontSee(__('ui.dashboard.deleted'));
        Livewire::actingAs($this->viewer())->test(Dashboard::class)
            ->assertDontSee(__('ui.dashboard.deleted'))
            ->assertSee(__('ui.dashboard.read_only'));
    }

    public function test_label_says_canonical_locations_not_unique_companies(): void
    {
        Livewire::actingAs($this->admin())->test(Dashboard::class)
            ->assertSee('Provider records (canonical locations)')
            ->assertDontSee('unique companies', false)
            ->assertSee('not one company', false);
    }

    public function test_per_type_groups_top_n_and_an_other_bucket(): void
    {
        foreach (range(1, 4) as $i) {
            $type = $this->type("Type $i", "نوع $i");
            foreach (range(1, 5 - $i) as $_) { // type 1 has 4 records ... type 4 has 1
                $this->provider(type: $type);
            }
        }

        $result = app(DashboardStats::class)->perType('en', top: 2);

        $this->assertSame(['Type 1', 'Type 2'], array_column($result['types'], 'label'));
        $this->assertSame([4, 3], array_column($result['types'], 'count'));
        $this->assertSame(3, $result['other']);      // 2 + 1
        $this->assertSame(2, $result['other_types']);
    }

    public function test_recently_updated_is_bounded_to_ten_and_ordered_newest_first(): void
    {
        $type = $this->type();
        foreach (range(1, 14) as $i) {
            $p = $this->provider(type: $type, en: ['name' => "P$i"]);
            DB::table('providers')->where('id', $p->id)->update(['updated_at' => now()->subMinutes(100 - $i)]);
        }

        $recent = app(DashboardStats::class)->recentlyUpdated(50);

        $this->assertCount(10, $recent);
        $this->assertSame('P14', $recent->first()->translation('en')->name);
    }

    public function test_dashboard_uses_a_small_constant_number_of_queries(): void
    {
        $type = $this->type();
        foreach (range(1, 3) as $_) {
            $this->provider(type: $type);
        }
        $admin = $this->admin();

        DB::enableQueryLog();
        Livewire::actingAs($admin)->test(Dashboard::class);
        $small = count(DB::getQueryLog());

        foreach (range(1, 30) as $_) {
            $this->provider(type: $this->type());
        }
        DB::flushQueryLog();
        Livewire::actingAs($admin)->test(Dashboard::class);
        $large = count(DB::getQueryLog());

        $this->assertSame($small, $large, 'Dashboard query count must not grow with the data.');
    }

    public function test_legacy_review_backlog_is_shown_to_admins_only(): void
    {
        $title = __('ui.dashboard.legacy.title');

        Livewire::actingAs($this->admin())->test(Dashboard::class)->assertSee($title);
        foreach (['editor', 'viewer'] as $role) {
            Livewire::actingAs($this->{$role}())->test(Dashboard::class)->assertDontSee($title);
        }

        $this->assertSame(['unpaired' => 0, 'suggestions' => 0, 'held' => 0, 'provisional' => 0], app(DashboardStats::class)->legacyBacklog());
    }
}
