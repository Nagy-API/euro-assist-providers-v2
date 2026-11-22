<?php

namespace Tests\Feature\Ui;

use App\Livewire\Types\Index;
use App\Models\FeaturedProvider;
use App\Models\ProviderType;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

class TypesTest extends UiTestCase
{
    public function test_only_admin_can_open_the_types_screen(): void
    {
        Livewire::actingAs($this->editor())->test(Index::class)->assertForbidden();
        Livewire::actingAs($this->viewer())->test(Index::class)->assertForbidden();
        Livewire::actingAs($this->admin())->test(Index::class)->assertOk();
    }

    public function test_list_shows_usage_counts_including_deleted_records(): void
    {
        $type = $this->type('Clinic', 'عيادة');
        $this->provider(type: $type);
        $this->provider(type: $type)->delete();

        $c = Livewire::actingAs($this->admin())->test(Index::class)->assertSee('Clinic')->assertSee('عيادة');

        $row = $c->viewData('types')->items()[0];
        $this->assertSame(1, $row->providers_count);
        $this->assertSame(2, $row->providers_with_deleted_count);
        $c->assertSee(__('ui.types.incl_deleted', ['count' => 1]));
    }

    public function test_admin_creates_a_type_with_names_in_both_languages_and_it_is_audited(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(Index::class)
            ->call('create')->assertSet('showForm', true)
            ->set('key', 'dental_lab')->set('name_en', 'Dental Lab')->set('name_ar', 'معمل أسنان')->set('sort_order', '7')
            ->call('save')->assertHasNoErrors()->assertSet('showForm', false);

        $type = ProviderType::query()->where('key', 'dental_lab')->with('translations')->firstOrFail();
        $this->assertSame(7, $type->sort_order);
        $this->assertTrue($type->is_active);
        $this->assertSame('Dental Lab', $type->label('en'));
        $this->assertSame('معمل أسنان', $type->label('ar'));
        $this->assertDatabaseHas('audit_events', ['action' => 'type.created', 'actor_id' => $admin->id]);
    }

    public function test_a_single_name_is_enough(): void
    {
        Livewire::actingAs($this->admin())->test(Index::class)->call('create')
            ->set('key', 'only_ar')->set('name_ar', 'نوع')->call('save')->assertHasNoErrors();

        $this->assertSame(['ar'], ProviderType::query()->where('key', 'only_ar')->firstOrFail()->translations->pluck('locale')->all());
    }

    public function test_validation_rules(): void
    {
        $this->type(attributes: ['key' => 'taken']);
        $c = Livewire::actingAs($this->admin())->test(Index::class)->call('create');

        $c->set('key', 'Bad Key!')->set('name_en', 'X')->call('save')->assertHasErrors(['key']);
        $c->set('key', 'taken')->call('save')->assertHasErrors(['key']);
        $c->set('key', '9starts_with_digit')->call('save')->assertHasErrors(['key']);
        $c->set('key', 'valid_key')->set('name_en', '')->set('name_ar', '')->call('save')->assertHasErrors(['name_en']);
        $c->set('name_en', 'X')->set('sort_order', '-1')->call('save')->assertHasErrors(['sort_order']);
        $c->set('sort_order', 'abc')->call('save')->assertHasErrors(['sort_order']);
        $this->assertDatabaseMissing('provider_types', ['key' => 'valid_key']);
    }

    public function test_editing_updates_names_order_and_active_flag(): void
    {
        $type = $this->type('Clinic', 'عيادة', ['key' => 'clinic_x', 'sort_order' => 3]);

        Livewire::actingAs($this->admin())->test(Index::class)
            ->call('edit', $type->id)->assertSet('key', 'clinic_x')->assertSet('name_ar', 'عيادة')
            ->set('name_en', 'Medical Clinic')->set('sort_order', '1')->set('is_active', false)
            ->call('save')->assertHasNoErrors();

        $fresh = $type->fresh()->load('translations');
        $this->assertSame('Medical Clinic', $fresh->label('en'));
        $this->assertSame(1, $fresh->sort_order);
        $this->assertFalse($fresh->is_active);
        $this->assertDatabaseHas('audit_events', ['action' => 'type.updated']);
    }

    public function test_key_is_immutable_once_providers_use_it(): void
    {
        $type = $this->type('Clinic', 'عيادة', ['key' => 'clinic_x']);
        $this->provider(type: $type);

        $c = Livewire::actingAs($this->admin())->test(Index::class)->call('edit', $type->id)
            ->assertSee(__('ui.types.key_locked_hint'));

        $c->set('key', 'renamed_key')->call('save')->assertHasErrors(['key']);
        $this->assertSame('clinic_x', $type->fresh()->key);
    }

    public function test_key_is_immutable_when_a_featured_entry_uses_it_and_editable_when_unused(): void
    {
        $used = $this->type(attributes: ['key' => 'used_by_featured']);
        FeaturedProvider::factory()->create(['provider_type_id' => $used->id]);
        $unused = $this->type(attributes: ['key' => 'unused_type']);

        Livewire::actingAs($this->admin())->test(Index::class)->call('edit', $used->id)->set('key', 'new_name')->call('save')->assertHasErrors(['key']);
        Livewire::actingAs($this->admin())->test(Index::class)->call('edit', $unused->id)->set('key', 'new_name')->call('save')->assertHasNoErrors();

        $this->assertSame('used_by_featured', $used->fresh()->key);
        $this->assertSame('new_name', $unused->fresh()->key);
    }

    public function test_there_is_no_way_to_delete_a_type(): void
    {
        $this->assertFalse(method_exists(Index::class, 'delete'));
        $this->assertFalse(method_exists(Index::class, 'performDelete'));
        $this->assertFalse($this->admin()->can('delete', $this->type()));
    }

    public function test_permission_is_rechecked_for_every_action_after_mount(): void
    {
        $type = $this->type(attributes: ['key' => 'keep_me']);

        $admin = $this->admin();
        // A forbidden response carries no component snapshot, so each attempt mounts afresh as an
        // Admin and then continues as a user who is no longer allowed to manage types.
        $attempts = [
            [fn ($c) => $c, fn ($c) => $c->call('create')],
            [fn ($c) => $c, fn ($c) => $c->call('edit', $type->id)],
            // The form is filled while still allowed; only the final save happens after losing the right.
            [fn ($c) => $c->call('create')->set('key', 'hacked')->set('name_en', 'x'), fn ($c) => $c->call('save')],
        ];
        foreach ([$this->editor(), $this->viewer()] as $user) {
            foreach ($attempts as [$prepare, $attempt]) {
                $c = $prepare(Livewire::actingAs($admin)->test(Index::class));
                $this->actingAs($user);
                $attempt($c)->assertForbidden();
            }
        }

        $this->assertDatabaseMissing('provider_types', ['key' => 'hacked']);
        $this->assertSame('keep_me', $type->fresh()->key);
    }

    public function test_editing_cannot_be_retargeted_from_the_client(): void
    {
        $type = $this->type();
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->admin())->test(Index::class)->set('editingId', $type->id);
    }
}
