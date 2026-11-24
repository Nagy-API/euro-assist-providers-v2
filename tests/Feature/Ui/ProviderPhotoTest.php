<?php

namespace Tests\Feature\Ui;

use App\Livewire\Providers\Photo;
use App\Models\AuditEvent;
use App\Models\Provider;
use App\Services\Media\TypeIconStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** Provider photos and type icons (ADR-018), synthetic images only. */
class ProviderPhotoTest extends UiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withoutVite();
    }

    private function photos(): array
    {
        return Storage::disk('local')->allFiles('provider-photos');
    }

    public function test_editor_uploads_replaces_and_removes_a_photo_that_viewers_can_see(): void
    {
        $provider = $this->provider();
        $editor = $this->editor();

        Livewire::actingAs($editor)->test(Photo::class, ['provider' => $provider])
            ->set('photo', UploadedFile::fake()->image('front.png', 200, 120))
            ->assertHasNoErrors()
            ->call('save')->assertHasNoErrors();

        $first = $provider->fresh()->photo_path;
        $this->assertNotNull($first);
        $this->assertMatchesRegularExpression('#^provider-photos/[0-9a-f-]{36}\.png$#', $first);
        $this->assertSame([$first], $this->photos());
        $this->assertSame(1, AuditEvent::query()->where('action', 'provider.photo.added')->count());

        $this->actingAs($this->viewer())->get(route('providers.photo', $provider))
            ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');

        Livewire::actingAs($editor)->test(Photo::class, ['provider' => $provider])
            ->set('photo', UploadedFile::fake()->image('new.jpg', 100, 100))->call('save')->assertHasNoErrors();
        $second = $provider->fresh()->photo_path;
        $this->assertNotSame($first, $second);
        $this->assertSame([$second], $this->photos(), 'the replaced file is deleted');

        Livewire::actingAs($editor)->test(Photo::class, ['provider' => $provider])->call('confirmRemove')->call('remove');
        $this->assertNull($provider->fresh()->photo_path);
        $this->assertSame([], $this->photos());
        $this->actingAs($this->viewer())->get(route('providers.photo', $provider))->assertNotFound();
    }

    public function test_photo_changes_move_the_revision_and_deleted_providers_are_never_changed(): void
    {
        $provider = $this->provider();
        $before = (int) $provider->fresh()->revision;
        Livewire::actingAs($this->editor())->test(Photo::class, ['provider' => $provider])
            ->set('photo', UploadedFile::fake()->image('a.png', 50, 50))->call('save');
        $this->assertSame($before + 1, (int) $provider->fresh()->revision, 'rollback and concurrent forms must see a photo change as an edit');

        $provider->delete();
        Livewire::actingAs($this->admin())->test(Photo::class, ['provider' => $provider])->call('remove')->assertNotFound();
        $this->assertNotNull(Provider::withTrashed()->find($provider->id)->photo_path);
    }

    public function test_a_fake_image_and_svg_are_refused(): void
    {
        $provider = $this->provider();

        Livewire::actingAs($this->editor())->test(Photo::class, ['provider' => $provider])
            ->set('photo', UploadedFile::fake()->createWithContent('photo.png', '<?php echo 1; ?>'))
            ->assertHasErrors('photo');
        Livewire::actingAs($this->editor())->test(Photo::class, ['provider' => $provider])
            ->set('photo', UploadedFile::fake()->createWithContent('photo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'))
            ->assertHasErrors('photo');
        Livewire::actingAs($this->editor())->test(Photo::class, ['provider' => $provider])
            ->set('photo', UploadedFile::fake()->image('big.png')->size(5000))
            ->assertHasErrors('photo');

        $this->assertNull($provider->fresh()->photo_path);
        $this->assertSame([], $this->photos());
    }

    public function test_viewer_cannot_upload_or_remove(): void
    {
        $provider = $this->provider(['photo_path' => 'provider-photos/00000000-0000-4000-8000-000000000000.png']);
        $viewer = $this->viewer();

        Livewire::actingAs($viewer)->test(Photo::class, ['provider' => $provider])
            ->call('_startUpload', 'photo', [['name' => 'a.png', 'size' => 100, 'type' => 'image/png']], false)->assertForbidden();
        Livewire::actingAs($viewer)->test(Photo::class, ['provider' => $provider])->call('save')->assertForbidden();
        Livewire::actingAs($viewer)->test(Photo::class, ['provider' => $provider])->call('remove')->assertForbidden();
        Livewire::actingAs($viewer)->test(Photo::class, ['provider' => $provider])->assertDontSee(__('ui.media.remove'));

        $this->assertNotNull($provider->fresh()->photo_path);
    }

    public function test_photo_route_requires_sign_in_and_rejects_foreign_paths(): void
    {
        $provider = $this->provider(['photo_path' => '../.env']);

        $this->get(route('providers.photo', $provider))->assertRedirect(route('login'));
        $this->actingAs($this->viewer())->get(route('providers.photo', $provider))->assertNotFound();
    }

    public function test_type_icon_is_the_fallback_picture_in_list_and_detail(): void
    {
        $image = UploadedFile::fake()->image('i.png');
        Storage::disk('local')->put('type-icons/11111111-1111-4111-8111-111111111111.png', (string) file_get_contents($image->getRealPath()));
        $type = $this->type('Laboratory', 'معمل', ['icon_path' => 'type-icons/11111111-1111-4111-8111-111111111111.png']);
        $provider = $this->provider([], [], [], $type);
        $viewer = $this->viewer();

        $this->actingAs($viewer)->get(route('types.icon', $type))->assertOk();
        $this->actingAs($viewer)->get(route('providers.show', $provider))->assertOk()->assertSee(route('types.icon', $type), false)->assertSee(__('ui.media.using_type_icon'));
        $this->actingAs($viewer)->get(route('providers.index'))->assertOk()->assertSee(route('types.icon', $type), false);
        $this->assertTrue(app(TypeIconStore::class)->exists($type->icon_path));
    }
}
