<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\ProviderType;
use App\Models\User;
use App\Services\ProviderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProviderServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProviderService $service;

    private ProviderType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProviderService::class);
        $this->type = ProviderType::factory()->create();
    }

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return array_replace_recursive([
            'provider_type_id' => $this->type->id,
            'phone' => '0100 000 1234',
            'translations' => [
                'en' => ['name' => 'Alpha Clinic', 'address' => '1 Test St', 'city' => 'Cairo'],
                'ar' => ['name' => 'عيادة ألفا', 'address' => 'شارع الاختبار ١', 'city' => 'القاهرة'],
            ],
        ], $override);
    }

    public function test_editor_can_create_a_bilingual_provider(): void
    {
        $editor = User::factory()->editor()->create();

        $provider = $this->service->create($editor, $this->payload());

        $this->assertCount(2, $provider->translations);
        $this->assertSame('0100 000 1234', $provider->phone);
        $this->assertSame($editor->id, $provider->created_by);
        $this->assertDatabaseHas('audit_events', ['action' => 'provider.created', 'target_id' => (string) $provider->id]);
    }

    public function test_one_language_only_is_allowed_and_no_translation_is_invented(): void
    {
        $provider = $this->service->create(User::factory()->editor()->create(), [
            'provider_type_id' => $this->type->id,
            'translations' => ['ar' => ['name' => 'فقط عربي', 'address' => 'عنوان']],
        ]);

        $this->assertSame(['ar'], $provider->translations->pluck('locale')->all());
    }

    public function test_create_requires_type_and_a_complete_translation(): void
    {
        $editor = User::factory()->editor()->create();

        foreach ([
            ['provider_type_id' => null],
            ['translations' => ['en' => ['name' => 'No address'], 'ar' => []]],
            ['translations' => ['en' => [], 'ar' => []]],
            ['provider_type_id' => 999999],
            ['email' => 'not-an-email'],
            ['latitude' => 95],
        ] as $bad) {
            try {
                $this->service->create($editor, array_replace($this->payload(), $bad));
                $this->fail('Expected validation failure for '.json_encode(array_keys($bad)));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertDatabaseCount('providers', 0);
    }

    public function test_unsupported_locale_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create(User::factory()->editor()->create(), $this->payload(['translations' => ['fr' => ['name' => 'x', 'address' => 'y']]]));
    }

    public function test_mass_assignment_of_protected_columns_is_ignored(): void
    {
        $provider = $this->service->create(User::factory()->editor()->create(), $this->payload(['revision' => 99, 'id' => 777, 'created_by' => 5, 'deleted_at' => now()->toDateTimeString()]));

        $this->assertNotSame(777, $provider->id);
        $this->assertSame(1, $provider->revision);
        $this->assertNull($provider->deleted_at);
    }

    public function test_blank_values_on_update_preserve_stored_data(): void
    {
        $editor = User::factory()->editor()->create();
        $provider = $this->service->create($editor, $this->payload());

        $updated = $this->service->update($editor, $provider, [
            'phone' => '', 'email' => null,
            'translations' => ['en' => ['name' => 'Alpha Clinic Renamed', 'address' => '', 'city' => ''], 'ar' => []],
        ]);

        $this->assertSame('0100 000 1234', $updated->phone, 'blank phone preserved');
        $this->assertSame('Alpha Clinic Renamed', $updated->translation('en')->name);
        $this->assertSame('1 Test St', $updated->translation('en')->address, 'blank address preserved');
        $this->assertSame('Cairo', $updated->translation('en')->city, 'blank city preserved');
        $this->assertSame('عيادة ألفا', $updated->translation('ar')->name);
        $this->assertSame(2, $updated->revision);
    }

    public function test_unchanged_update_does_not_bump_revision(): void
    {
        $editor = User::factory()->editor()->create();
        $provider = $this->service->create($editor, $this->payload());

        $same = $this->service->update($editor, $provider, ['phone' => '0100 000 1234']);

        $this->assertSame(1, $same->revision);
    }

    public function test_clearing_a_value_is_a_separate_admin_only_operation(): void
    {
        $admin = User::factory()->admin()->create();
        $editor = User::factory()->editor()->create();
        $provider = $this->service->create($admin, $this->payload());

        try {
            $this->service->update($editor, $provider, [], clear: ['phone']);
            $this->fail('Editor must not clear values');
        } catch (AuthorizationException) {
        }
        $this->assertSame('0100 000 1234', $provider->fresh()->phone);
        $this->assertDatabaseHas('audit_events', ['action' => 'authorization.denied', 'result' => 'denied', 'actor_id' => $editor->id]);

        $cleared = $this->service->update($admin, $provider, [], clear: ['phone', 'translations.en.city']);
        $this->assertNull($cleared->phone);
        $this->assertNull($cleared->translation('en')->city);
        $this->assertSame('Alpha Clinic', $cleared->translation('en')->name);
    }

    public function test_required_translation_fields_and_unknown_paths_cannot_be_cleared(): void
    {
        $admin = User::factory()->admin()->create();
        $provider = $this->service->create($admin, $this->payload());

        foreach (['translations.en.name', 'translations.en.address', 'provider_type_id', 'id', 'translations.fr.city'] as $path) {
            try {
                $this->service->update($admin, $provider, [], clear: [$path]);
                $this->fail("Should not clear $path");
            } catch (ValidationException) {
            }
        }
        $this->assertSame('Alpha Clinic', $provider->fresh('translations')->translation('en')->name);
    }

    public function test_delete_and_restore_are_admin_only_and_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $editor = User::factory()->editor()->create();
        $viewer = User::factory()->viewer()->create();
        $provider = $this->service->create($admin, $this->payload());

        foreach ([$editor, $viewer] as $denied) {
            try {
                $this->service->delete($denied, $provider);
                $this->fail('delete must be denied');
            } catch (AuthorizationException) {
            }
        }
        $this->assertNotNull(Provider::query()->find($provider->id));

        $this->service->delete($admin, $provider);
        $this->assertNull(Provider::query()->find($provider->id));

        $this->expectException(AuthorizationException::class);
        $this->service->restore($editor, $provider);
    }

    public function test_viewer_cannot_create_or_update(): void
    {
        $viewer = User::factory()->viewer()->create();
        $provider = Provider::factory()->bilingual()->create();

        $this->assertThrows(fn () => $this->service->create($viewer, $this->payload()), AuthorizationException::class);
        $this->assertThrows(fn () => $this->service->update($viewer, $provider, ['phone' => '1']), AuthorizationException::class);
    }

    public function test_bulk_update_requires_preview_and_confirmation(): void
    {
        $editor = User::factory()->editor()->create();
        $other = ProviderType::factory()->create();
        $a = Provider::factory()->bilingual()->create();
        $b = Provider::factory()->bilingual()->create();

        // Without a preview token nothing is written.
        $this->assertThrows(fn () => $this->service->applyBulk($editor, 'forged-token'), ValidationException::class);
        $this->assertSame($this->originalType($a), $a->fresh()->provider_type_id);

        $preview = $this->service->previewBulk($editor, [$a->id, $b->id], ['provider_type_id' => $other->id]);
        $this->assertSame(2, $preview['count']);
        $this->assertSame($a->provider_type_id, $a->fresh()->provider_type_id, 'preview writes nothing');

        // A different actor cannot use someone else's token.
        $this->assertThrows(fn () => $this->service->applyBulk(User::factory()->editor()->create(), $preview['token']), ValidationException::class);

        $this->assertSame(2, $this->service->applyBulk($editor, $preview['token']));
        $this->assertSame($other->id, $a->fresh()->provider_type_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'provider.bulk_updated', 'actor_id' => $editor->id]);

        // Tokens are single use.
        $this->assertThrows(fn () => $this->service->applyBulk($editor, $preview['token']), ValidationException::class);
    }

    public function test_bulk_write_is_denied_for_viewers_and_bulk_delete_for_editors(): void
    {
        $provider = Provider::factory()->bilingual()->create();

        $this->assertThrows(fn () => $this->service->previewBulk(User::factory()->viewer()->create(), [$provider->id], ['is_active' => false]), AuthorizationException::class);
        $this->assertThrows(fn () => $this->service->bulkDelete(User::factory()->editor()->create(), [$provider->id]), AuthorizationException::class);
        $this->assertThrows(fn () => $this->service->bulkRestore(User::factory()->editor()->create(), [$provider->id]), AuthorizationException::class);

        $admin = User::factory()->admin()->create();
        $this->assertSame(1, $this->service->bulkDelete($admin, [$provider->id]));
        $this->assertSame(1, $this->service->bulkRestore($admin, [$provider->id]));
    }

    public function test_bulk_preview_with_no_changes_or_too_many_ids_is_rejected(): void
    {
        $editor = User::factory()->editor()->create();

        $this->assertThrows(fn () => $this->service->previewBulk($editor, [1], []), ValidationException::class);
        $this->assertThrows(fn () => $this->service->previewBulk($editor, range(1, 1001), ['is_active' => false]), ValidationException::class);
        $this->assertThrows(fn () => $this->service->previewBulk($editor, [1], ['role' => 'admin', 'is_active' => null]), ValidationException::class);
    }

    private function originalType(Provider $p): int
    {
        return $p->provider_type_id;
    }
}
