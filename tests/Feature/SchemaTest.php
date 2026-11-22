<?php

namespace Tests\Feature;

use App\Models\FeaturedProvider;
use App\Models\LegacyIdMap;
use App\Models\LegacyMigrationBatch;
use App\Models\Provider;
use App\Models\ProviderType;
use Database\Seeders\ProviderTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_type_seeder_creates_exactly_thirty_bilingual_types_and_is_idempotent(): void
    {
        $this->seed(ProviderTypeSeeder::class);
        $this->seed(ProviderTypeSeeder::class);

        $this->assertDatabaseCount('provider_types', 30);
        $this->assertDatabaseCount('provider_type_translations', 60);
        $this->assertSame('Laboratory', ProviderType::query()->where('key', 'laboratory')->firstOrFail()->label('en'));
        $this->assertSame('صيدلية', ProviderType::query()->where('key', 'pharmacy')->firstOrFail()->label('ar'));
    }

    public function test_translation_is_unique_per_provider_and_locale(): void
    {
        $provider = Provider::factory()->bilingual()->create();

        $this->expectException(QueryException::class);
        $provider->translations()->create(['locale' => 'en', 'name' => 'Dup', 'address' => 'Dup']);
    }

    public function test_provider_with_a_single_language_is_supported(): void
    {
        $provider = Provider::factory()->arabicOnly()->create();

        $this->assertCount(1, $provider->load('translations')->translations);
        $this->assertNull($provider->translation('en'));
        $this->assertSame('ar', $provider->displayTranslation('en')->locale, 'falls back to the only real translation');
    }

    public function test_phone_hours_and_discount_are_stored_losslessly_as_text(): void
    {
        $provider = Provider::factory()->create(['phone' => '0100 123 4567 / 02-2345678']);
        $provider->translations()->create([
            'locale' => 'en', 'name' => 'N', 'address' => 'A', 'hours' => "Sat-Thu 9am-5pm\nFri closed",
            'discount_text' => '10% to 20% discount; 15% off imported', 'package_text' => 'Gold: 1,500 EGP',
        ]);

        $fresh = Provider::query()->with('translations')->findOrFail($provider->id);
        $this->assertSame('0100 123 4567 / 02-2345678', $fresh->phone);
        $this->assertSame("Sat-Thu 9am-5pm\nFri closed", $fresh->translation('en')->hours);
        $this->assertSame('10% to 20% discount; 15% off imported', $fresh->translation('en')->discount_text);
    }

    public function test_leading_zero_phone_survives_a_round_trip(): void
    {
        $provider = Provider::factory()->create(['phone' => '01012345678']);

        $this->assertSame('01012345678', $provider->fresh()->phone);
    }

    public function test_type_in_use_cannot_be_deleted(): void
    {
        $provider = Provider::factory()->create();

        $this->expectException(QueryException::class);
        $provider->type()->delete();
    }

    public function test_soft_deleted_providers_are_hidden_but_restorable(): void
    {
        $provider = Provider::factory()->bilingual()->create();
        $provider->delete();

        $this->assertNull(Provider::query()->find($provider->id));
        $this->assertNotNull(Provider::withTrashed()->find($provider->id));
        $this->assertDatabaseCount('provider_translations', 2); // translations are kept while soft-deleted

        $provider->restore();
        $this->assertNotNull(Provider::query()->find($provider->id));
    }

    public function test_featured_providers_have_no_provider_foreign_key(): void
    {
        $this->assertFalse(\Schema::hasColumn('featured_providers', 'provider_id'));
        $featured = FeaturedProvider::factory()->create();

        $this->assertSame($featured->name_en, $featured->name('en'));
        $this->assertSame($featured->name_ar, $featured->name('ar'));
    }

    public function test_search_treats_wildcards_literally(): void
    {
        $a = Provider::factory()->create();
        $a->translations()->create(['locale' => 'en', 'name' => '100% Care', 'address' => 'x']);
        $b = Provider::factory()->create();
        $b->translations()->create(['locale' => 'en', 'name' => 'Plain Care', 'address' => 'x']);

        $this->assertSame([$a->id], Provider::query()->search('100%')->pluck('id')->all());
        $this->assertSame([], Provider::query()->search('%%%_')->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], Provider::query()->search('Care')->pluck('id')->all());
    }

    public function test_legacy_mapping_enforces_one_identity_per_source_row(): void
    {
        $batch = LegacyMigrationBatch::query()->create([
            'snapshot_sha256' => str_repeat('a', 64), 'mapping_version' => 'v1',
        ]);
        $row = ['source_table' => 'providers', 'source_id' => 5, 'source_locale' => 'en',
            'snapshot_sha256' => str_repeat('a', 64), 'migration_batch_id' => $batch->id];
        LegacyIdMap::query()->create($row);

        $this->expectException(QueryException::class);
        LegacyIdMap::query()->create($row);
    }
}
