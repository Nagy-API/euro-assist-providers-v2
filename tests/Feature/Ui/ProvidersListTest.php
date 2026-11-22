<?php

namespace Tests\Feature\Ui;

use App\Livewire\Providers\Index;
use App\Models\ExportJob;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Services\Transfers\ExportService;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use LogicException;

class ProvidersListTest extends UiTestCase
{
    private function names(Testable $component): array
    {
        return collect($component->viewData('providers')->items())
            ->map(fn (Provider $p) => $p->translation('en')?->name ?? $p->translation('ar')?->name)
            ->all();
    }

    public function test_lists_providers_with_translation_badges_and_placeholders(): void
    {
        $this->provider(['phone' => null], en: ['name' => 'Alpha Clinic', 'city' => null], ar: ['city' => null]);

        Livewire::actingAs($this->viewer())->test(Index::class)
            ->assertSee('Alpha Clinic')
            ->assertSee('Arabic: available')  // sr-only coverage text
            ->assertSeeHtml('aria-label="'.__('ui.common.not_set').'"') // missing phone placeholder
            ->assertSee('—');
    }

    public function test_search_matches_names_in_both_languages_and_phone(): void
    {
        $this->provider(['phone' => '+20 100 555'], en: ['name' => 'Alpha Clinic'], ar: ['name' => 'عيادة ألفا']);
        $this->provider(['phone' => '0222'], en: ['name' => 'Beta Lab'], ar: ['name' => 'معمل بيتا']);

        $c = Livewire::actingAs($this->viewer())->test(Index::class);

        $this->assertSame(['Alpha Clinic'], $this->names($c->set('search', 'Alpha')));
        $this->assertSame(['Beta Lab'], $this->names($c->set('search', 'بيتا')));
        $this->assertSame(['Alpha Clinic'], $this->names($c->set('search', '555')));
    }

    public function test_search_wildcards_are_literal(): void
    {
        $this->provider(en: ['name' => '100% Care'], ar: ['name' => 'رعاية']);
        $this->provider(en: ['name' => '100X Care'], ar: ['name' => 'رعاية ٢']);
        $this->provider(en: ['name' => 'Under_score Co'], ar: ['name' => 'شركة']);
        $this->provider(en: ['name' => 'UnderXscore Co'], ar: ['name' => 'شركة ٢']);

        $c = Livewire::actingAs($this->viewer())->test(Index::class);

        $this->assertSame(['100% Care'], $this->names($c->set('search', '%')));
        $this->assertSame(['Under_score Co'], $this->names($c->set('search', '_')));
    }

    public function test_filters_by_type_city_district_and_status(): void
    {
        $clinic = $this->type('Clinic', 'عيادة');
        $lab = $this->type('Laboratory', 'معمل');
        $this->provider(type: $clinic, en: ['name' => 'In Cairo', 'city' => 'Cairo', 'district' => 'Nasr City']);
        $this->provider(['is_active' => false], type: $lab, en: ['name' => 'In Giza', 'city' => 'Giza', 'district' => 'Dokki'], ar: ['city' => 'الجيزة']);

        $c = Livewire::actingAs($this->viewer())->test(Index::class);

        $this->assertSame(['In Giza'], $this->names($c->set('type', (string) $lab->id)));
        $c->set('type', '');
        $this->assertSame(['In Cairo'], $this->names($c->set('city', 'Cairo')));
        $this->assertSame([], $this->names($c->set('city', 'cair')), 'city is an exact match, not a substring');
        $c->set('city', '');
        $this->assertSame(['In Giza'], $this->names($c->set('district', 'Dokki')));
        $c->set('district', '');
        $this->assertSame(['In Giza'], $this->names($c->set('status', 'inactive')));
        $this->assertSame(['In Cairo'], $this->names($c->set('status', 'active')));
    }

    public function test_city_filter_is_an_exact_match_in_any_translation(): void
    {
        $this->provider(en: ['name' => 'Cairo Place', 'city' => 'Cairo'], ar: ['city' => 'القاهرة']);

        app()->setLocale('ar');
        $c = Livewire::actingAs($this->viewer())->test(Index::class);

        $this->assertSame(['Cairo Place'], $this->names($c->set('city', 'القاهرة')));
        // Exact match in any translation (single-language migrated records must stay findable).
        $this->assertSame(['Cairo Place'], $this->names($c->set('city', 'Cairo')));
        $this->assertSame([], $this->names($c->set('city', 'Cair')), 'exact match only, not a substring');
    }

    public function test_deleted_filter_is_admin_only(): void
    {
        $live = $this->provider(en: ['name' => 'Live One']);
        $gone = $this->provider(en: ['name' => 'Gone One']);
        $gone->delete();

        $editor = Livewire::actingAs($this->editor())->test(Index::class)->set('trashed', 'only');
        $this->assertSame(['Live One'], $this->names($editor), 'an Editor cannot reveal deleted providers by tampering with the filter');

        $admin = Livewire::actingAs($this->admin())->test(Index::class);
        $this->assertSame(['Live One'], $this->names($admin));
        $this->assertSame(['Gone One'], $this->names($admin->set('trashed', 'only')));
        $this->assertEqualsCanonicalizing(['Live One', 'Gone One'], $this->names($admin->set('trashed', 'with')));
        $this->assertNotNull($live);
    }

    public function test_sort_is_whitelisted_and_invalid_url_state_is_normalized(): void
    {
        $this->provider(en: ['name' => 'B'], ar: ['name' => 'ب']);
        $this->provider(en: ['name' => 'A'], ar: ['name' => 'أ']);

        $c = Livewire::actingAs($this->viewer())
            ->withQueryParams(['sort' => 'phone; DROP TABLE providers', 'dir' => 'sideways', 'perPage' => 'abc', 'status' => 'zzz', 'type' => '1 OR 1=1'])
            ->test(Index::class);

        $c->assertSet('sort', 'updated_at')->assertSet('dir', 'desc')->assertSet('status', '')->assertSet('type', '');
        $this->assertCount(2, $this->names($c));
        $this->assertSame(2, DB::table('providers')->count());

        $c->call('sortBy', 'password');   // not whitelisted: ignored
        $c->assertSet('sort', 'updated_at');

        $this->assertSame(['A', 'B'], $this->names($c->call('sortBy', 'name')));
        $this->assertSame(['B', 'A'], $this->names($c->call('sortBy', 'name')), 'second click reverses direction');
    }

    public function test_name_sort_uses_the_display_locale(): void
    {
        $this->provider(en: ['name' => 'Zeta'], ar: ['name' => 'أول']);
        $this->provider(en: ['name' => 'Alpha'], ar: ['name' => 'ثاني']);

        $c = Livewire::actingAs($this->viewer())->test(Index::class)->call('sortBy', 'name');
        $this->assertSame(['Alpha', 'Zeta'], $this->names($c));

        app()->setLocale('ar');
        $ar = Livewire::actingAs($this->viewer())->test(Index::class)->call('sortBy', 'name');
        $this->assertSame(['Zeta', 'Alpha'], $this->names($ar), 'Arabic names أول < ثاني');
    }

    public function test_sorting_by_type_city_and_id_works(): void
    {
        $a = $this->type('Aardvark', 'أ');
        $z = $this->type('Zebra', 'ي');
        $this->provider(type: $z, en: ['name' => 'Z-type', 'city' => 'Alexandria']);
        $this->provider(type: $a, en: ['name' => 'A-type', 'city' => 'Zagazig']);

        $c = Livewire::actingAs($this->viewer())->test(Index::class);

        $this->assertSame(['A-type', 'Z-type'], $this->names($c->call('sortBy', 'type')));
        $this->assertSame(['Z-type', 'A-type'], $this->names($c->call('sortBy', 'city')));
        $this->assertSame(['Z-type', 'A-type'], $this->names($c->call('sortBy', 'id')->call('sortBy', 'id')), 'id desc by default, then asc after toggling would flip');
    }

    public function test_per_page_is_bounded_and_pagination_works(): void
    {
        $type = $this->type();
        foreach (range(1, 30) as $_) {
            $this->provider(type: $type);
        }

        $c = Livewire::actingAs($this->viewer())->test(Index::class);
        $this->assertCount(25, $c->viewData('providers')->items());
        $this->assertSame(30, $c->viewData('providers')->total());

        $c->set('perPage', '10');
        $this->assertCount(10, $c->viewData('providers')->items());

        $c->set('perPage', '100000');
        $this->assertCount(25, $c->viewData('providers')->items(), 'out-of-range perPage falls back to the configured default');

        $c->set('perPage', '10')->call('gotoPage', 3);
        $this->assertSame(3, $c->viewData('providers')->currentPage());
    }

    public function test_eager_loading_keeps_query_count_constant_as_rows_grow(): void
    {
        $viewer = $this->viewer();
        $count = function (int $rows) use ($viewer): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($viewer)->test(Index::class);

            return count(DB::getQueryLog());
        };

        foreach (range(1, 3) as $_) {
            $this->provider(type: $this->type());
        }
        $small = $count(3);

        foreach (range(1, 20) as $_) {
            $this->provider(type: $this->type());
        }
        $large = $count(23);

        $this->assertSame($small, $large, 'Rendering more rows must not add queries (N+1).');
    }

    public function test_query_count_is_also_constant_when_sorting_by_joined_columns(): void
    {
        $viewer = $this->viewer();
        $run = function () use ($viewer): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($viewer)->test(Index::class)->call('sortBy', 'type');

            return count(DB::getQueryLog());
        };

        foreach (range(1, 3) as $_) {
            $this->provider(type: $this->type());
        }
        $small = $run();
        foreach (range(1, 15) as $_) {
            $this->provider(type: $this->type());
        }

        $this->assertSame($small, $run());
    }

    public function test_shows_a_visible_marker_when_displaying_another_locales_text(): void
    {
        $this->englishOnlyProvider('Only English Lab');

        app()->setLocale('ar');
        Livewire::actingAs($this->viewer())->test(Index::class)
            ->assertSee('Only English Lab')
            ->assertSee('معروض بالإنجليزية');

        app()->setLocale('en');
        Livewire::actingAs($this->viewer())->test(Index::class)
            ->assertDontSee('Shown in English');
    }

    public function test_empty_states_distinguish_no_data_from_no_matches(): void
    {
        Livewire::actingAs($this->viewer())->test(Index::class)->assertSee(__('ui.providers.none_yet'));

        $this->provider();
        Livewire::actingAs($this->viewer())->test(Index::class)->set('search', 'zzzz-no-match')
            ->assertSee(__('ui.providers.no_results'));
    }

    // ------------------------------------------------------------------ row actions

    public function test_editor_and_viewer_cannot_delete_or_restore_even_by_direct_call(): void
    {
        $provider = $this->provider();
        $gone = $this->provider();
        $gone->delete();

        foreach ([$this->editor(), $this->viewer()] as $user) {
            Livewire::actingAs($user)->test(Index::class)->call('confirmDelete', $provider->id)->assertForbidden();
            Livewire::actingAs($user)->test(Index::class)->call('confirmRestore', $gone->id)->assertForbidden();
        }

        $this->assertNull($provider->fresh()->deleted_at);
        $this->assertNotNull($gone->fresh()->deleted_at);
    }

    public function test_locked_target_cannot_be_tampered_with_from_the_client(): void
    {
        $provider = $this->provider();

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->admin())->test(Index::class)->set('targetId', $provider->id);
    }

    public function test_perform_delete_cannot_be_called_without_authorization(): void
    {
        $provider = $this->provider();

        // Skipping confirmDelete and calling performDelete directly is still authorized per call.
        Livewire::actingAs($this->editor())->test(Index::class)->call('performDelete')->assertForbidden();
        $this->assertNull($provider->fresh()->deleted_at);
    }

    public function test_admin_deletes_and_restores_through_confirmation_dialogs_and_it_is_audited(): void
    {
        $provider = $this->provider();
        $admin = $this->admin();

        $c = Livewire::actingAs($admin)->test(Index::class)
            ->call('confirmDelete', $provider->id)
            ->assertSet('dialog', 'delete');
        $this->assertNull($provider->fresh()->deleted_at, 'nothing is deleted before confirmation');

        $c->call('performDelete')->assertSet('dialog', null)->assertSee(__('ui.providers.deleted'));
        $this->assertNotNull($provider->fresh()->deleted_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'provider.deleted', 'actor_id' => $admin->id, 'target_id' => (string) $provider->id]);

        $c->set('trashed', 'only')->call('confirmRestore', $provider->id)->call('performRestore');
        $this->assertNull($provider->fresh()->deleted_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'provider.restored']);
    }

    // ------------------------------------------------------------------ bulk

    public function test_bulk_update_requires_preview_then_confirmation(): void
    {
        $from = $this->type('Old', 'قديم');
        $to = $this->type('New', 'جديد');
        $a = $this->provider(type: $from);
        $b = $this->provider(type: $from);
        $other = $this->provider(type: $from);
        $editor = $this->editor();

        $c = Livewire::actingAs($editor)->test(Index::class)
            ->set('selected', [(string) $a->id, (string) $b->id])
            ->call('openBulk')->assertSet('dialog', 'bulk')
            ->set('bulkType', (string) $to->id)->set('bulkActive', '0')
            ->call('previewBulk');

        $c->assertSet('bulkPreview.count', 2)->assertSet('bulkPreview.type', 'New')->assertSet('bulkPreview.active', 'inactive');
        $this->assertSame($from->id, $a->fresh()->provider_type_id, 'preview must not write');
        $this->assertDatabaseMissing('audit_events', ['action' => 'provider.bulk_updated']);

        $c->call('applyBulk')->assertSet('dialog', null)->assertSee(__('ui.providers.bulk_applied', ['count' => 2]));

        $this->assertSame($to->id, $a->fresh()->provider_type_id);
        $this->assertFalse($b->fresh()->is_active);
        $this->assertSame($from->id, $other->fresh()->provider_type_id, 'unselected rows are untouched');
        $this->assertDatabaseHas('audit_events', ['action' => 'provider.bulk_updated', 'actor_id' => $editor->id]);
    }

    public function test_bulk_apply_without_a_previewed_plan_changes_nothing(): void
    {
        $type = $this->type();
        $a = $this->provider(type: $type);
        $to = $this->type('New', 'جديد');

        Livewire::actingAs($this->editor())->test(Index::class)
            ->set('selected', [(string) $a->id])
            ->call('openBulk')->set('bulkType', (string) $to->id)
            ->call('applyBulk');

        $this->assertSame($type->id, $a->fresh()->provider_type_id);
    }

    public function test_bulk_preview_rejects_an_empty_change_and_unknown_type(): void
    {
        $a = $this->provider();

        Livewire::actingAs($this->editor())->test(Index::class)
            ->set('selected', [(string) $a->id])->call('openBulk')
            ->call('previewBulk')->assertHasErrors(['changes'])
            ->set('bulkType', '99999')->call('previewBulk')->assertHasErrors(['provider_type_id']);
    }

    public function test_bulk_actions_without_selection_do_nothing(): void
    {
        Livewire::actingAs($this->editor())->test(Index::class)->call('openBulk')
            ->assertSet('dialog', null)->assertSee(__('ui.providers.nothing_selected'));
    }

    public function test_viewer_cannot_open_bulk_update_and_editor_cannot_bulk_delete_or_restore(): void
    {
        $a = $this->provider();

        Livewire::actingAs($this->viewer())->test(Index::class)->set('selected', [(string) $a->id])->call('openBulk')->assertForbidden();
        Livewire::actingAs($this->viewer())->test(Index::class)->call('previewBulk')->assertForbidden();
        Livewire::actingAs($this->viewer())->test(Index::class)->call('applyBulk')->assertForbidden();

        foreach (['openBulkDelete', 'performBulkDelete', 'openBulkRestore', 'performBulkRestore'] as $method) {
            Livewire::actingAs($this->editor())->test(Index::class)->set('selected', [(string) $a->id])->call($method)->assertForbidden();
        }
        $this->assertNull($a->fresh()->deleted_at);
    }

    public function test_admin_bulk_deletes_and_restores_selected_providers(): void
    {
        $a = $this->provider();
        $b = $this->provider();
        $keep = $this->provider();
        $admin = $this->admin();

        $c = Livewire::actingAs($admin)->test(Index::class)
            ->set('selected', [(string) $a->id, (string) $b->id])
            ->call('openBulkDelete')->assertSet('dialog', 'bulk_delete')
            ->call('performBulkDelete')->assertSee(__('ui.providers.bulk_deleted', ['count' => 2]));

        $this->assertNotNull($a->fresh()->deleted_at);
        $this->assertNotNull($b->fresh()->deleted_at);
        $this->assertNull($keep->fresh()->deleted_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'provider.bulk_deleted', 'actor_id' => $admin->id]);

        $c->set('trashed', 'only')->set('selected', [(string) $a->id])->call('openBulkRestore')->call('performBulkRestore');
        $this->assertNull($a->fresh()->deleted_at);
    }

    public function test_selection_ids_from_the_client_are_sanitized(): void
    {
        $a = $this->provider();

        $c = Livewire::actingAs($this->admin())->test(Index::class)
            ->set('selected', [(string) $a->id, 'abc', '1; DROP TABLE providers', '-5', '1.5'])
            ->call('openBulkDelete')->call('performBulkDelete');

        $this->assertNotNull($a->fresh()->deleted_at);
        $this->assertSame(1, DB::table('providers')->count());
        $c->assertSet('selected', []);
    }

    public function test_changing_filters_clears_the_selection(): void
    {
        $a = $this->provider();

        Livewire::actingAs($this->editor())->test(Index::class)
            ->set('selected', [(string) $a->id])->set('search', 'x')->assertSet('selected', []);
    }

    // ------------------------------------------------------------------ export

    public function test_export_buttons_show_a_friendly_notice_while_the_service_is_a_placeholder(): void
    {
        $this->mock(ExportService::class)->shouldReceive('dispatch')->andThrow(new LogicException('not implemented'));
        $a = $this->provider();

        Livewire::actingAs($this->editor())->test(Index::class)
            ->call('exportFiltered')->assertSee(__('ui.providers.export_unavailable'))
            ->set('selected', [(string) $a->id])->call('exportSelected')->assertSee(__('ui.providers.export_unavailable'));
    }

    public function test_export_passes_scope_locale_filters_and_selected_ids(): void
    {
        $type = $this->type();
        $a = $this->provider(type: $type);
        $editor = $this->editor();

        $this->mock(ExportService::class)->shouldReceive('dispatch')->once()
            ->withArgs(function ($user, $scope, $locale, $filters, $ids) use ($editor, $type, $a) {
                return $user->is($editor) && $scope === 'selected' && $locale === 'en'
                    && $filters === ['type' => (string) $type->id, 'status' => 'active'] && $ids === [$a->id];
            })->andReturn((new ExportJob)->forceFill(['id' => 7]));

        Livewire::actingAs($editor)->test(Index::class)
            ->set('type', (string) $type->id)->set('status', 'active')->set('trashed', 'only')
            ->set('selected', [(string) $a->id])->call('exportSelected')
            ->assertSee(__('ui.providers.export_queued', ['id' => 7]));
    }

    public function test_export_requires_the_exports_permission_and_a_selection(): void
    {
        $this->mock(ExportService::class)->shouldNotReceive('dispatch');
        $a = $this->provider();

        Livewire::actingAs($this->viewer())->test(Index::class)->call('exportFiltered')->assertForbidden();
        Livewire::actingAs($this->viewer())->test(Index::class)->set('selected', [(string) $a->id])->call('exportSelected')->assertForbidden();
        Livewire::actingAs($this->editor())->test(Index::class)->call('exportSelected')->assertSee(__('ui.providers.nothing_selected'));
    }

    public function test_export_buttons_are_not_rendered_for_viewers(): void
    {
        $this->provider();

        Livewire::actingAs($this->viewer())->test(Index::class)->assertDontSee(__('ui.providers.export_filtered'));
        Livewire::actingAs($this->editor())->test(Index::class)->assertSee(__('ui.providers.export_filtered'));
    }

    public function test_url_state_round_trips(): void
    {
        $type = ProviderType::factory()->create();
        Livewire::actingAs($this->viewer())
            ->withQueryParams(['q' => 'Alpha', 'type' => (string) $type->id, 'status' => 'inactive', 'sort' => 'name', 'dir' => 'asc'])
            ->test(Index::class)
            ->assertSet('search', 'Alpha')->assertSet('type', (string) $type->id)->assertSet('status', 'inactive')
            ->assertSet('sort', 'name')->assertSet('dir', 'asc');
    }

    public function test_city_filter_finds_single_language_records_from_either_interface_language(): void
    {
        $type = $this->type();
        $ar = Provider::factory()->create(['provider_type_id' => $type->id]);
        $ar->translations()->create(['locale' => 'ar', 'name' => 'معمل تجريبي', 'address' => 'شارع', 'city' => 'القاهرة الكبري']);
        $en = Provider::factory()->create(['provider_type_id' => $type->id]);
        $en->translations()->create(['locale' => 'en', 'name' => 'Test Lab', 'address' => 'Road', 'city' => 'Great Cairo']);

        app()->setLocale('en');
        $c = Livewire::actingAs($this->viewer())->test(Index::class)->set('city', 'القاهرة الكبري');
        $c->assertSee('معمل تجريبي')->assertDontSee('Test Lab');

        app()->setLocale('ar');
        $c = Livewire::actingAs($this->viewer())->test(Index::class)->set('city', 'Great Cairo');
        $c->assertSee('Test Lab')->assertDontSee('معمل تجريبي');
    }
}
