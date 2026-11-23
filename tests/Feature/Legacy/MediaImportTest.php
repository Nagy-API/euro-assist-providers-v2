<?php

namespace Tests\Feature\Legacy;

use App\Models\FeaturedProvider;
use App\Models\LegacyIdMap;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** legacy:import-media (ADR-018) against a SYNTHETIC archive folder with generated images. */
class MediaImportTest extends LegacyTestCase
{
    private string $archive = '';

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->archive = sys_get_temp_dir().DIRECTORY_SEPARATOR.'legacy-archive-'.bin2hex(random_bytes(4));
        mkdir($this->archive.'/uploads/logos', 0777, true);
        foreach (['laboratories.png', 'clinics.png', 'hospitals.png', 'custom_photo.png', 'brand.jpg'] as $name) {
            $image = UploadedFile::fake()->image($name, 64, 64); // keep the reference: the temp file lives with it
            copy($image->getRealPath(), $this->archive.'/uploads/logos/'.$name);
        }
        file_put_contents($this->archive.'/uploads/logos/fake.png', 'not an image');
        file_put_contents($this->archive.'/secret.txt', 'outside the logos folder');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->archive.'/uploads/logos/*') ?: [] as $f) {
            @unlink($f);
        }
        @unlink($this->archive.'/secret.txt');
        @rmdir($this->archive.'/uploads/logos');
        @rmdir($this->archive.'/uploads');
        @rmdir($this->archive);
        parent::tearDown();
    }

    private function seedRows(): void
    {
        $this->en(['id' => 1, 'logo_path' => 'uploads/logos/custom_photo.png']);
        $this->en(['id' => 2, 'logo_path' => 'uploads/logos/laboratories.png']);   // its own type icon
        $this->en(['id' => 3, 'logo_path' => 'uploads/logos/missing.png']);
        $this->en(['id' => 4, 'logo_path' => 'uploads/logos/../../secret.txt']);
        $this->en(['id' => 5, 'logo_path' => 'https://cdn.example.test/logo.png']);
        $this->en(['id' => 6, 'logo_path' => 'uploads/logos/fake.png']);
        $this->ar(['id' => 7, 'logo_path' => 'uploads/logos/hospitals.png']);      // another type's icon: copied as a photo
        $this->top(['id' => 8, 'logo_path' => 'brand.jpg']);
        $this->actor = $this->admin();
        $this->runPipeline($this->actor);
    }

    private function providerFor(string $table, int $id): ?Provider
    {
        $providerId = LegacyIdMap::query()->where('source_table', $table)->where('source_id', $id)->value('provider_id');

        return $providerId === null ? null : Provider::query()->find($providerId);
    }

    public function test_only_verified_archive_images_are_copied_and_everything_else_is_reported(): void
    {
        $this->seedRows();

        $this->artisan('legacy:import-media', ['archive' => $this->archive, '--dry-run' => true])->assertSuccessful();
        $this->assertSame([], Storage::disk('local')->allFiles(), 'a dry run copies nothing');

        $this->artisan('legacy:import-media', ['archive' => $this->archive, '--actor' => $this->actor->email])->assertSuccessful();

        $this->assertNotNull($this->providerFor('providers', 1)?->photo_path);
        $this->assertNull($this->providerFor('providers', 2)?->photo_path, 'shown through the type icon, as in V1');
        $this->assertNull($this->providerFor('providers', 3)?->photo_path);
        $this->assertNull($this->providerFor('providers', 4)?->photo_path);
        $this->assertNull($this->providerFor('providers', 5)?->photo_path);
        $this->assertNull($this->providerFor('providers', 6)?->photo_path);
        $this->assertNotNull($this->providerFor('arabic_providers', 7)?->photo_path);
        $this->assertSame('uploads/logos/custom_photo.png', $this->providerFor('providers', 1)?->logo_path, 'the raw reference is kept');

        $featured = FeaturedProvider::query()->firstOrFail();
        $this->assertMatchesRegularExpression('#^featured-logos/[0-9a-f-]{36}\.jpg$#', (string) $featured->logo_path);

        $lab = ProviderType::query()->where('key', 'laboratory')->firstOrFail();
        $dental = ProviderType::query()->where('key', 'dental_clinic')->first();
        $this->assertNotNull($lab->icon_path);
        if ($dental !== null) {
            $clinic = ProviderType::query()->where('key', 'clinic')->firstOrFail();
            $this->assertSame($clinic->icon_path, $dental->icon_path, 'V1 used the clinic icon for every other type');
        }

        // Nothing outside uploads/logos was read or copied.
        foreach (Storage::disk('local')->allFiles() as $file) {
            $this->assertStringNotContainsString('outside the logos folder', (string) Storage::disk('local')->get($file));
        }

        // Re-running never overwrites.
        $before = $this->providerFor('providers', 1)?->photo_path;
        $this->artisan('legacy:import-media', ['archive' => $this->archive, '--actor' => $this->actor->email])->assertSuccessful();
        $this->assertSame($before, $this->providerFor('providers', 1)?->photo_path);
    }

    public function test_requires_an_admin_actor_and_a_logos_folder(): void
    {
        $this->seedRows();

        $this->artisan('legacy:import-media', ['archive' => $this->archive])->assertFailed();
        $this->artisan('legacy:import-media', ['archive' => $this->archive.'/uploads', '--actor' => $this->actor->email])->assertFailed();
        $this->artisan('legacy:import-media', ['archive' => $this->archive, '--dry-run' => true, '--report' => base_path('storage/media-report.json')])->assertFailed();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
