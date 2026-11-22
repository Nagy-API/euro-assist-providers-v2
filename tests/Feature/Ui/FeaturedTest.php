<?php

namespace Tests\Feature\Ui;

use App\Livewire\Featured\Index;
use App\Models\FeaturedProvider;
use App\Services\Ui\FeaturedLogoStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class FeaturedTest extends UiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function storedFiles(): array
    {
        return Storage::disk('local')->allFiles('featured-logos');
    }

    // ------------------------------------------------------------------ read-only list

    public function test_everyone_with_view_can_list_but_only_admin_sees_management_controls(): void
    {
        FeaturedProvider::factory()->create(['name_en' => 'Brand One', 'name_ar' => 'علامة واحد']);

        foreach ([$this->viewer(), $this->editor()] as $user) {
            Livewire::actingAs($user)->test(Index::class)
                ->assertSee('Brand One')->assertSee(__('ui.featured.read_only'))
                ->assertDontSee(__('ui.featured.add'));
        }
        Livewire::actingAs($this->admin())->test(Index::class)->assertSee('Brand One')->assertSee(__('ui.featured.add'));
    }

    public function test_sort_order_and_active_are_labelled_as_v2_options(): void
    {
        FeaturedProvider::factory()->create();

        Livewire::actingAs($this->viewer())->test(Index::class)->assertSee(__('ui.featured.v2_option'));
    }

    // ------------------------------------------------------------------ forbidden access (direct calls)

    public function test_editor_and_viewer_cannot_create_update_or_delete_by_direct_livewire_calls(): void
    {
        $item = FeaturedProvider::factory()->create(['name_en' => 'Protected']);

        foreach ([$this->editor(), $this->viewer()] as $user) {
            Livewire::actingAs($user)->test(Index::class)->call('create')->assertForbidden();
            Livewire::actingAs($user)->test(Index::class)->call('edit', $item->id)->assertForbidden();
            Livewire::actingAs($user)->test(Index::class)->set('name_en', 'Injected')->call('save')->assertForbidden();
            Livewire::actingAs($user)->test(Index::class)->call('confirmDelete', $item->id)->assertForbidden();
            Livewire::actingAs($user)->test(Index::class)->call('performDelete')->assertForbidden();
        }

        $this->assertSame(1, FeaturedProvider::query()->count());
        $this->assertSame('Protected', $item->fresh()->name_en);
    }

    public function test_editor_cannot_even_start_or_finish_a_logo_upload(): void
    {
        foreach ([$this->editor(), $this->viewer()] as $user) {
            Livewire::actingAs($user)->test(Index::class)
                ->call('_startUpload', 'logo', [['name' => 'a.png', 'size' => 100, 'type' => 'image/png']], false)
                ->assertForbidden();
            Livewire::actingAs($user)->test(Index::class)
                ->call('_finishUpload', 'logo', ['forged-reference'], false)
                ->assertForbidden();
        }

        $this->assertSame([], $this->storedFiles());
    }

    public function test_permission_is_rechecked_on_every_request_not_only_on_mount(): void
    {
        $item = FeaturedProvider::factory()->create(['name_en' => 'Stays']);
        $admin = $this->admin();
        $editor = $this->editor();
        // A forbidden response carries no component snapshot, so each attempt mounts afresh.
        foreach ([fn ($c) => $c->call('confirmDelete', $item->id), fn ($c) => $c->call('create')] as $attempt) {
            $c = Livewire::actingAs($admin)->test(Index::class);
            $this->actingAs($editor); // the session user lost the right to manage
            $attempt($c)->assertForbidden();
        }

        $this->assertSame('Stays', $item->fresh()->name_en);
    }

    // ------------------------------------------------------------------ admin CRUD

    public function test_admin_creates_updates_and_deletes_a_featured_provider(): void
    {
        $admin = $this->admin();
        $type = $this->type();

        $c = Livewire::actingAs($admin)->test(Index::class)
            ->call('create')
            ->set('name_en', 'Brand Co')->set('name_ar', '')->set('provider_type_id', (string) $type->id)->set('sort_order', '4')
            ->call('save')->assertHasNoErrors();

        $item = FeaturedProvider::query()->firstOrFail();
        $this->assertSame('Brand Co', $item->name_en);
        $this->assertNull($item->name_ar);
        $this->assertSame($type->id, $item->provider_type_id);
        $this->assertSame(4, $item->sort_order);
        $this->assertSame($admin->id, $item->created_by);
        $this->assertDatabaseHas('audit_events', ['action' => 'featured.created']);

        $c->call('edit', $item->id)->set('name_ar', 'شركة')->set('is_active', false)->call('save')->assertHasNoErrors();
        $this->assertSame('شركة', $item->fresh()->name_ar);
        $this->assertFalse($item->fresh()->is_active);

        $c->call('confirmDelete', $item->id)->assertSet('deletingId', $item->id)->call('performDelete');
        $this->assertSame(0, FeaturedProvider::query()->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'featured.deleted']);
    }

    public function test_at_least_one_name_is_required_and_other_fields_validated(): void
    {
        $c = Livewire::actingAs($this->admin())->test(Index::class)->call('create');

        $c->set('name_en', '')->set('name_ar', '')->call('save')->assertHasErrors(['name_en']);
        $c->set('name_en', 'X')->set('sort_order', '-3')->call('save')->assertHasErrors(['sort_order']);
        $c->set('sort_order', '0')->set('provider_type_id', '9999')->call('save')->assertHasErrors(['provider_type_id']);
        $c->set('provider_type_id', '')->set('name_en', str_repeat('a', 300))->call('save')->assertHasErrors(['name_en']);
        $this->assertSame(0, FeaturedProvider::query()->count());
    }

    // ------------------------------------------------------------------ logo handling

    public function test_valid_logo_is_stored_privately_with_a_random_server_generated_name(): void
    {
        $file = UploadedFile::fake()->image('../../evil name.png', 60, 40);

        Livewire::actingAs($this->admin())->test(Index::class)->call('create')
            ->set('name_en', 'With Logo')->set('logo', $file)->assertHasNoErrors()->call('save')->assertHasNoErrors();

        $item = FeaturedProvider::query()->firstOrFail();
        $this->assertMatchesRegularExpression('#^featured-logos/[0-9a-f-]{36}\.png$#', $item->logo_path, 'stored name is random, never the client name');
        $this->assertStringNotContainsString('evil', $item->logo_path);
        $this->assertSame([$item->logo_path], $this->storedFiles());
        $this->assertNotFalse(@getimagesizefromstring(Storage::disk('local')->get($item->logo_path)), 'stored file is a real image');
    }

    public function test_logo_is_served_through_the_authenticated_route_with_safe_headers(): void
    {
        $this->admin();
        $png = Storage::disk('local')->put('featured-logos/6f1c1b8e-0a47-4c5e-9a53-2f0f0f0f0f0f.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $this->assertTrue($png);
        $item = FeaturedProvider::factory()->create(['logo_path' => 'featured-logos/6f1c1b8e-0a47-4c5e-9a53-2f0f0f0f0f0f.png']);

        $this->get(route('featured.logo', $item))->assertRedirect(route('login'));

        $response = $this->actingAs($this->viewer())->get(route('featured.logo', $item))->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_missing_logo_returns_404_and_the_list_shows_a_placeholder(): void
    {
        $item = FeaturedProvider::factory()->create(['logo_path' => null]);

        $this->actingAs($this->viewer())->get(route('featured.logo', $item))->assertNotFound();
        Livewire::actingAs($this->viewer())->test(Index::class)->assertSee(__('ui.featured.no_logo'));
    }

    /** @return array<string, array{0: string}> */
    public static function traversalPaths(): array
    {
        return [
            'parent dir' => ['featured-logos/../../.env'],
            'absolute' => ['/etc/passwd'],
            'other directory' => ['imports/6f1c1b8e-0a47-4c5e-9a53-2f0f0f0f0f0f.png'],
            'windows separators' => ['featured-logos\\..\\..\\.env'],
            'client name' => ['featured-logos/evil.php'],
            'null byte' => ["featured-logos/6f1c1b8e-0a47-4c5e-9a53-2f0f0f0f0f0f.png\0.php"],
            'svg' => ['featured-logos/6f1c1b8e-0a47-4c5e-9a53-2f0f0f0f0f0f.svg'],
        ];
    }

    #[DataProvider('traversalPaths')]
    public function test_stored_paths_that_escape_the_logo_directory_are_never_served(string $path): void
    {
        Storage::disk('local')->put('secret.txt', 'top secret');
        Storage::disk('local')->put('imports/6f1c1b8e-0a47-4c5e-9a53-2f0f0f0f0f0f.png', 'not-a-logo');
        $item = FeaturedProvider::factory()->create(['logo_path' => $path]);

        $this->actingAs($this->admin())->get(route('featured.logo', $item))->assertNotFound();
        $this->assertFalse(app(FeaturedLogoStore::class)->isManagedPath($path));
    }

    public function test_deleting_with_a_hostile_stored_path_never_deletes_foreign_files(): void
    {
        Storage::disk('local')->put('imports/keep.png', 'keep');
        $item = FeaturedProvider::factory()->create(['logo_path' => 'featured-logos/../imports/keep.png']);

        Livewire::actingAs($this->admin())->test(Index::class)->call('confirmDelete', $item->id)->call('performDelete');

        $this->assertTrue(Storage::disk('local')->exists('imports/keep.png'));
    }

    public function test_svg_is_rejected_even_when_renamed_to_png(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        Livewire::actingAs($this->admin())->test(Index::class)->call('create')->set('name_en', 'X')
            ->set('logo', $svg)->assertHasErrors(['logo']);
        $this->assertSame([], $this->storedFiles());
    }

    public function test_non_image_files_are_rejected(): void
    {
        foreach ([
            UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
            UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;'),
            UploadedFile::fake()->createWithContent('fake.jpg', 'this is not an image'),
            UploadedFile::fake()->createWithContent('anim.gif', base64_decode('R0lGODlhAQABAAAAACw=')),
        ] as $file) {
            Livewire::actingAs($this->admin())->test(Index::class)->call('create')->set('name_en', 'X')
                ->set('logo', $file)->assertHasErrors(['logo']);
        }
        $this->assertSame([], $this->storedFiles());
    }

    public function test_oversized_logo_is_rejected(): void
    {
        $max = (int) config('providers.featured.max_logo_kb');
        $big = UploadedFile::fake()->image('big.png', 50, 50)->size($max + 50);

        Livewire::actingAs($this->admin())->test(Index::class)->call('create')->set('name_en', 'X')
            ->set('logo', $big)->assertHasErrors(['logo']);
        $this->assertSame([], $this->storedFiles());
    }

    public function test_the_store_rejects_decompression_bomb_dimensions_and_non_images_directly(): void
    {
        $store = app(FeaturedLogoStore::class);

        $this->expectException(ValidationException::class);
        $store->store(UploadedFile::fake()->createWithContent('x.png', 'plain text'));
    }

    public function test_replacing_a_logo_deletes_the_old_file_and_removing_clears_it(): void
    {
        $admin = $this->admin();
        $c = Livewire::actingAs($admin)->test(Index::class)->call('create')->set('name_en', 'Rotating')
            ->set('logo', UploadedFile::fake()->image('a.jpg', 30, 30))->call('save')->assertHasNoErrors();
        $item = FeaturedProvider::query()->firstOrFail();
        $first = $item->logo_path;
        $this->assertCount(1, $this->storedFiles());

        $c->call('edit', $item->id)->set('logo', UploadedFile::fake()->image('b.png', 30, 30))->call('save')->assertHasNoErrors();
        $second = $item->fresh()->logo_path;
        $this->assertNotSame($first, $second);
        $this->assertSame([$second], $this->storedFiles(), 'old logo file removed');

        $c->call('edit', $item->id)->set('removeLogo', true)->call('save')->assertHasNoErrors();
        $this->assertNull($item->fresh()->logo_path);
        $this->assertSame([], $this->storedFiles());
    }

    public function test_deleting_a_featured_provider_removes_its_logo_file(): void
    {
        Livewire::actingAs($this->admin())->test(Index::class)->call('create')->set('name_en', 'Gone')
            ->set('logo', UploadedFile::fake()->image('a.png', 30, 30))->call('save');
        $item = FeaturedProvider::query()->firstOrFail();
        $this->assertCount(1, $this->storedFiles());

        Livewire::actingAs($this->admin())->test(Index::class)->call('confirmDelete', $item->id)->call('performDelete');

        $this->assertSame([], $this->storedFiles());
    }

    public function test_a_failed_save_does_not_leave_an_orphaned_upload(): void
    {
        $c = Livewire::actingAs($this->admin())->test(Index::class)->call('create')
            ->set('name_en', '')->set('name_ar', '')
            ->set('logo', UploadedFile::fake()->image('a.png', 30, 30))
            ->call('save')->assertHasErrors(['name_en']);

        $this->assertSame([], $this->storedFiles(), 'validation failure must happen before anything is stored');
        $this->assertNotNull($c);
    }
}
