<?php

namespace Tests\Feature\Ui;

use App\Livewire\Providers\Form;
use App\Models\AuditEvent;
use App\Models\Provider;
use App\Services\ProviderService;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

class ProviderFormTest extends UiTestCase
{
    private function fillEnglish(Testable $c, string $name = 'New Clinic'): Testable
    {
        return $c->set('translations.en.name', $name)->set('translations.en.address', '5 Example Street');
    }

    // ------------------------------------------------------------------ authorization

    public function test_viewer_cannot_open_the_create_or_edit_form(): void
    {
        $provider = $this->provider();

        Livewire::actingAs($this->viewer())->test(Form::class)->assertForbidden();
        Livewire::actingAs($this->viewer())->test(Form::class, ['provider' => $provider])->assertForbidden();
    }

    public function test_viewer_cannot_save_even_if_the_component_was_reached_by_a_crafted_request(): void
    {
        $provider = $this->provider();
        $editor = $this->editor();

        // Mount as an editor, then continue the same component as a viewer: save() must re-authorize.
        $c = Livewire::actingAs($editor)->test(Form::class, ['provider' => $provider])->set('phone', '555');
        $this->actingAs($this->viewer());
        $c->call('save')->assertForbidden();

        $this->assertNotSame('555', $provider->fresh()->phone);
    }

    // ------------------------------------------------------------------ create

    public function test_editor_creates_a_provider_with_both_languages(): void
    {
        $type = $this->type();
        $editor = $this->editor();

        $c = Livewire::actingAs($editor)->test(Form::class)
            ->set('provider_type_id', (string) $type->id)
            ->set('translations.en.name', 'Delta Hospital')->set('translations.en.address', '1 Main St')->set('translations.en.city', 'Cairo')
            ->set('translations.ar.name', 'مستشفى دلتا')->set('translations.ar.address', '١ الشارع الرئيسي')
            ->set('phone', '0100 123 4567 / 02-2222')->set('email', 'info@example.test')
            ->set('website', 'https://example.test')->set('map_url', 'https://maps.example.test/?q=1')
            ->set('latitude', '30.0444')->set('longitude', '31.2357')
            ->call('save');

        $provider = Provider::query()->with('translations')->latest('id')->firstOrFail();
        $c->assertRedirect(route('providers.show', $provider));
        $this->assertSame('0100 123 4567 / 02-2222', $provider->phone, 'phone is preserved exactly as typed');
        $this->assertSame($type->id, $provider->provider_type_id);
        $this->assertSame('Delta Hospital', $provider->translation('en')->name);
        $this->assertSame('مستشفى دلتا', $provider->translation('ar')->name);
        $this->assertSame($editor->id, $provider->created_by);
        $this->assertSame(1, $provider->revision);
        $this->assertDatabaseHas('audit_events', ['action' => 'provider.created', 'actor_id' => $editor->id]);
    }

    public function test_a_single_language_is_enough_and_nothing_is_fabricated(): void
    {
        $type = $this->type();

        $c = Livewire::actingAs($this->editor())->test(Form::class)->set('provider_type_id', (string) $type->id);
        $this->fillEnglish($c)->call('save')->assertHasNoErrors();

        $provider = Provider::query()->with('translations')->firstOrFail();
        $this->assertCount(1, $provider->translations);
        $this->assertNull($provider->translation('ar'), 'no Arabic translation may be invented');
    }

    public function test_arabic_only_creation_is_allowed(): void
    {
        $type = $this->type();

        Livewire::actingAs($this->editor())->test(Form::class)->set('provider_type_id', (string) $type->id)
            ->set('translations.ar.name', 'معمل')->set('translations.ar.address', 'عنوان')->call('save')->assertHasNoErrors();

        $this->assertSame(['ar'], Provider::query()->firstOrFail()->translations->pluck('locale')->all());
    }

    public function test_create_surfaces_service_validation_errors_next_to_fields(): void
    {
        $type = $this->type();

        // No type, no translation at all.
        Livewire::actingAs($this->editor())->test(Form::class)->call('save')
            ->assertHasErrors(['provider_type_id', 'translations'])
            ->assertSee(__('ui.form.fix_errors'));

        // A language with some content but no name/address.
        $c = Livewire::actingAs($this->editor())->test(Form::class)
            ->set('provider_type_id', (string) $type->id)->set('translations.ar.city', 'القاهرة')
            ->call('save');
        $c->assertHasErrors(['translations.ar.name', 'translations.ar.address'])->assertSet('tab', 'ar');
        $this->assertSame(0, Provider::query()->count());
    }

    public function test_field_level_validation(): void
    {
        $type = $this->type();
        $c = Livewire::actingAs($this->editor())->test(Form::class)->set('provider_type_id', (string) $type->id);
        $this->fillEnglish($c)->set('email', 'not-an-email')->set('latitude', '123')->set('longitude', 'abc')->set('website', 'has space.com')
            ->call('save')
            ->assertHasErrors(['email', 'latitude', 'longitude', 'website']);

        $this->assertSame(0, Provider::query()->count());
    }

    public function test_tampered_type_and_overlong_text_are_rejected(): void
    {
        $c = Livewire::actingAs($this->editor())->test(Form::class)->set('provider_type_id', '99999');
        $this->fillEnglish($c)->set('translations.en.city', str_repeat('x', 300))->call('save')
            ->assertHasErrors(['provider_type_id', 'translations.en.city']);
    }

    // ------------------------------------------------------------------ edit / ADR-008

    public function test_edit_loads_stored_values_and_blank_fields_preserve_them(): void
    {
        $provider = $this->provider(['phone' => '0100 000', 'email' => 'a@example.test'], ['city' => 'Cairo', 'district' => 'Nasr City']);
        $editor = $this->editor();

        $c = Livewire::actingAs($editor)->test(Form::class, ['provider' => $provider])
            ->assertSet('phone', '0100 000')->assertSet('translations.en.district', 'Nasr City')
            ->assertSet('revision', 1)
            ->assertSee(__('ui.form.blank_preserves'));

        $c->set('phone', '')->set('email', '')->set('translations.en.district', '')->set('translations.en.name', 'Renamed Clinic')
            ->call('save')->assertRedirect(route('providers.show', $provider));

        $fresh = $provider->fresh()->load('translations');
        $this->assertSame('0100 000', $fresh->phone, 'a blank phone must not delete the stored value');
        $this->assertSame('a@example.test', $fresh->email);
        $this->assertSame('Nasr City', $fresh->translation('en')->district);
        $this->assertSame('Renamed Clinic', $fresh->translation('en')->name);
        $this->assertSame(2, $fresh->revision);
        $this->assertSame($editor->id, $fresh->updated_by);
    }

    public function test_editor_does_not_see_clear_toggles(): void
    {
        $provider = $this->provider(['phone' => '0100']);

        Livewire::actingAs($this->editor())->test(Form::class, ['provider' => $provider])
            ->assertDontSee(__('ui.form.clear_value'));
        Livewire::actingAs($this->admin())->test(Form::class, ['provider' => $provider])
            ->assertSee(__('ui.form.clear_value'));
    }

    public function test_editor_tampering_with_the_clear_property_is_rejected_and_nothing_changes(): void
    {
        $provider = $this->provider(['phone' => '0100 000'], ['city' => 'Cairo']);

        Livewire::actingAs($this->editor())->test(Form::class, ['provider' => $provider])
            ->set('clear', ['phone', 'translations.en.city'])
            ->call('save')->assertForbidden();

        $fresh = $provider->fresh()->load('translations');
        $this->assertSame('0100 000', $fresh->phone);
        $this->assertSame('Cairo', $fresh->translation('en')->city);
        $this->assertSame(1, $fresh->revision);
        $this->assertDatabaseMissing('audit_events', ['action' => 'provider.updated']);
    }

    public function test_admin_can_explicitly_clear_values_and_it_is_audited(): void
    {
        $provider = $this->provider(['phone' => '0100 000', 'website' => 'https://x.test'], ['city' => 'Cairo'], ['city' => 'القاهرة']);
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(Form::class, ['provider' => $provider])
            ->set('clear', ['phone', 'translations.ar.city'])
            ->call('save')->assertRedirect(route('providers.show', $provider));

        $fresh = $provider->fresh()->load('translations');
        $this->assertNull($fresh->phone);
        $this->assertNull($fresh->translation('ar')->city);
        $this->assertSame('Cairo', $fresh->translation('en')->city, 'only the marked values are cleared');
        $this->assertSame('https://x.test', $fresh->website);
        $event = AuditEvent::query()->where('action', 'provider.updated')->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertContains('phone (cleared)', $event->metadata['changed_fields']);
    }

    public function test_clear_wins_over_typed_input_and_invalid_clear_paths_are_rejected(): void
    {
        $provider = $this->provider(['phone' => '0100 000']);

        Livewire::actingAs($this->admin())->test(Form::class, ['provider' => $provider])
            ->set('clear', ['translations.en.name'])   // required fields can never be cleared
            ->call('save')->assertHasErrors(['clear']);

        Livewire::actingAs($this->admin())->test(Form::class, ['provider' => $provider])
            ->set('phone', '999')->set('clear', ['phone'])
            ->call('save');
        $this->assertNull($provider->fresh()->phone);
    }

    public function test_a_stale_revision_is_rejected_with_a_clear_message_and_nothing_is_overwritten(): void
    {
        $provider = $this->provider(['phone' => '111']);
        $editor = $this->editor();

        $c = Livewire::actingAs($editor)->test(Form::class, ['provider' => $provider])->set('phone', '222');

        // Someone else saves first.
        app(ProviderService::class)->update($this->admin(), $provider->fresh(), ['phone' => '333']);
        $this->assertSame(2, $provider->fresh()->revision);

        $c->call('save')
            ->assertSet('conflict', true)
            ->assertSee(__('ui.form.conflict_title'))
            ->assertNoRedirect();

        $this->assertSame('333', $provider->fresh()->phone, 'the other editor\'s change must survive');
        $this->assertSame(2, $provider->fresh()->revision);

        $c->call('reload')->assertSet('conflict', false)->assertSet('phone', '333')->assertSet('revision', 2);
        $c->set('phone', '444')->call('save')->assertRedirect();
        $this->assertSame('444', $provider->fresh()->phone);
    }

    public function test_revision_and_provider_id_cannot_be_tampered_with(): void
    {
        $provider = $this->provider();
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->editor())->test(Form::class, ['provider' => $provider])->set('revision', 99);
    }

    public function test_provider_id_cannot_be_retargeted(): void
    {
        $a = $this->provider();
        $b = $this->provider();
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->editor())->test(Form::class, ['provider' => $a])->set('providerId', $b->id);
    }

    public function test_deleted_providers_cannot_be_edited(): void
    {
        $provider = $this->provider();
        $provider->delete();

        $this->actingAs($this->admin())->get("/providers/{$provider->id}/edit")->assertNotFound();
    }

    public function test_status_and_type_can_be_changed_and_inactive_current_type_stays_selectable(): void
    {
        $inactive = $this->type('Legacy', 'قديم', ['is_active' => false]);
        $active = $this->type('Modern', 'حديث');
        $provider = $this->provider(type: $inactive);

        $c = Livewire::actingAs($this->editor())->test(Form::class, ['provider' => $provider])
            ->assertSee('Legacy')->assertSee('Modern');

        $c->set('provider_type_id', (string) $active->id)->set('is_active', false)->call('save');

        $fresh = $provider->fresh();
        $this->assertSame($active->id, $fresh->provider_type_id);
        $this->assertFalse($fresh->is_active);
    }

    public function test_type_labels_follow_the_interface_language(): void
    {
        $this->type('Clinic', 'عيادة');

        app()->setLocale('ar');
        Livewire::actingAs($this->editor())->test(Form::class)->assertSee('عيادة')->assertSee(__('ui.form.create_title'));
    }

    public function test_html_in_text_fields_is_escaped_on_render(): void
    {
        $provider = $this->provider(en: ['name' => '<script>alert(1)</script>']);

        Livewire::actingAs($this->editor())->test(Form::class, ['provider' => $provider])
            ->assertDontSeeHtml('<script>alert(1)</script>');
    }
}
