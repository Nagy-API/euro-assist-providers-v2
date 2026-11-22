<?php

namespace Tests\Feature\Legacy;

use App\Models\AuditEvent;
use App\Models\FeaturedProvider;
use App\Models\LegacyIdMap;
use App\Models\LegacyMigrationBatch;
use App\Models\LegacyStagingRow;
use App\Models\LegacyTypeMapping;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ApplyTest extends LegacyTestCase
{
    public function test_each_source_locale_row_becomes_one_unpaired_provider_with_one_translation(): void
    {
        $en = $this->en(['phone' => '0100 000 0001', 'discount_pct' => '10% to 20% discount', 'hours' => 'Sat-Thu 9-5', 'package' => 'A, B']);
        $ar = $this->ar(['phone' => '01000000009']);

        $batch = $this->runPipeline();

        $this->assertSame(2, Provider::query()->count());
        $this->assertSame(2, DB::table('provider_translations')->count());

        $enMap = LegacyIdMap::query()->where('source_table', 'providers')->where('source_id', $en)->firstOrFail();
        $arMap = LegacyIdMap::query()->where('source_table', 'arabic_providers')->where('source_id', $ar)->firstOrFail();
        $this->assertNotSame($enMap->provider_id, $arMap->provider_id, 'rows are never merged by the pipeline');
        foreach ([$enMap, $arMap] as $map) {
            $this->assertSame(['unpaired', null, self::SHA, $batch->id], [$map->status, $map->match_method, $map->snapshot_sha256, $map->migration_batch_id]);
        }
        $this->assertSame(['en'], Provider::query()->findOrFail($enMap->provider_id)->translations->pluck('locale')->all());
        $this->assertSame(['ar'], Provider::query()->findOrFail($arMap->provider_id)->translations->pluck('locale')->all());

        $p = Provider::query()->with('translations')->findOrFail($enMap->provider_id);
        $this->assertSame($batch->id, $p->migration_batch_id);
        $this->assertSame('0100 000 0001', $p->phone, 'phone kept as lossless text');
        $t = $p->translation('en');
        $this->assertSame(['10% to 20% discount', 'Sat-Thu 9-5', 'A, B'], [$t->discount_text, $t->hours, $t->package_text]);
    }

    public function test_leading_zeros_coordinates_and_the_raw_logo_string_are_preserved_without_copying_media(): void
    {
        Storage::fake('local');
        $this->en([
            'phone' => '01000000001', 'map_url' => 'https://maps.example.test/?q=30.0444196,31.2357116', 'logo_path' => 'legacy/logos/raw-name.png',
        ]);
        $this->en(['map_url' => 'https://maps.example.test/?q=95,31', 'phone' => '0123']);

        $this->runPipeline();

        $p = Provider::query()->orderBy('id')->get();
        $this->assertSame('01000000001', $p[0]->phone);
        $this->assertEquals([30.0444196, 31.2357116], [(float) $p[0]->latitude, (float) $p[0]->longitude]);
        $this->assertSame('https://maps.example.test/?q=30.0444196,31.2357116', $p[0]->map_url, 'original map_url kept');
        $this->assertSame('legacy/logos/raw-name.png', $p[0]->logo_path, 'raw logo string kept');
        $this->assertSame('0123', $p[1]->phone);
        $this->assertSame([null, null], [$p[1]->latitude, $p[1]->longitude], 'out-of-range coordinates are rejected, not stored');
        $this->assertSame('https://maps.example.test/?q=95,31', $p[1]->map_url);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'no media is copied');
    }

    public function test_every_staged_row_ends_in_exactly_one_state_and_counts_reconcile(): void
    {
        $this->en();                                                    // mapped
        $this->en(['type' => 'Laboratory ']);                           // trailing space variant: mapped
        $this->en(['type' => 'laboratory']);                            // key-style: mapped
        $this->en(['type' => 'Laboratories']);                          // plural: needs_review (D3)
        $this->en(['type' => 'Invented Category']);                     // unmapped: needs_review
        $this->en(['type' => null]);                                    // blank: needs_review
        $this->en(['name' => '   ']);                                   // blank name: rejected
        $this->en(['address' => null]);                                 // blank address: needs_review
        $this->ar();
        $this->ar(['type' => 'تصنيف غير معروف']);
        $this->top();
        $this->top(['type_en' => 'mystery', 'type_ar' => 'mystery']);   // featured, unmapped: needs_review
        $this->top(['name_english' => '', 'name_arabic' => ' ']);       // featured, no name: rejected

        $batch = $this->runPipeline();

        $rows = LegacyStagingRow::query()->where('migration_batch_id', $batch->id)->get();
        $this->assertCount(13, $rows);
        $this->assertCount(0, $rows->where('state', 'staged'), 'nothing is left unprocessed');

        $byState = $rows->groupBy('state')->map->count()->sortKeys()->all();
        $this->assertSame(['mapped' => 5, 'needs_review' => 6, 'rejected' => 2], $byState);
        $this->assertSame(
            ['missing_address', 'type_blank', 'type_needs_review', 'type_unmapped', 'type_unmapped', 'type_unmapped'],
            $rows->where('state', 'needs_review')->pluck('reason')->sort()->values()->all(),
        );
        $this->assertSame(['missing_name', 'missing_name'], $rows->where('state', 'rejected')->pluck('reason')->values()->all());

        // mapped rows <-> id maps, one each; needs_review/rejected rows created nothing.
        $this->assertSame(5, LegacyIdMap::query()->count());
        $this->assertSame(4, Provider::query()->count());
        $this->assertSame(1, FeaturedProvider::query()->count());

        $this->artisan('legacy:reconcile')->assertSuccessful();
    }

    public function test_unmapped_ambiguous_and_review_pending_types_are_never_guessed(): void
    {
        $this->en(['type' => 'Laboratories']);

        $this->runPipeline();

        $this->assertSame(0, Provider::query()->count());
        $row = LegacyStagingRow::query()->firstOrFail();
        $this->assertSame(['needs_review', 'type_needs_review'], [$row->state, $row->reason]);

        $mapping = LegacyTypeMapping::query()->where('raw_value', 'Laboratories')->firstOrFail();
        $this->assertSame('needs_review', $mapping->status);
        $this->assertNotNull($mapping->note, 'the recommendation is recorded with a note');
        $this->assertSame(ProviderType::query()->where('key', 'laboratory')->value('id'), $mapping->provider_type_id);
    }

    public function test_a_row_in_review_is_re_evaluated_after_the_owner_approves_the_mapping(): void
    {
        $this->en(['type' => 'Laboratories']);
        $batch = $this->runPipeline();
        $this->assertSame(0, Provider::query()->count());

        LegacyTypeMapping::query()->where('raw_value', 'Laboratories')->update(['status' => 'approved']);
        $this->artisan('legacy:apply', ['--actor' => User::query()->firstOrFail()->email])->assertSuccessful();

        $this->assertSame(1, Provider::query()->count());
        $this->assertSame('mapped', LegacyStagingRow::query()->firstOrFail()->state);
        $this->assertSame('applied', $batch->fresh()->status);
    }

    public function test_same_numeric_id_with_different_names_is_not_paired(): void
    {
        $this->en(['id' => 7, 'name' => 'Alpha Clinic Cairo']);
        $this->ar(['id' => 7, 'name' => 'مختبر بيتا الجيزة']);

        $this->runPipeline();
        $this->artisan('legacy:suggest-pairs', ['--min-signals' => 1])->assertSuccessful();

        $maps = LegacyIdMap::query()->get();
        $this->assertSame(['unpaired', 'unpaired'], $maps->pluck('status')->all());
        $this->assertCount(2, $maps->pluck('provider_id')->unique());
        $this->assertSame(2, Provider::query()->count());
        $this->assertSame(['en'], Provider::query()->findOrFail($maps[0]->provider_id)->translations->pluck('locale')->all());
        // Even with --min-signals=1 the id match is only a SUGGESTION awaiting a human decision.
        $this->assertSame(['suggested'], DB::table('legacy_pair_suggestions')->pluck('status')->unique()->all());
    }

    public function test_the_same_phone_across_different_brands_is_not_paired(): void
    {
        $phone = '0155 555 5555';
        $this->en(['name' => 'Brand One Branch', 'phone' => $phone]);
        $this->en(['name' => 'Brand Two Branch', 'phone' => $phone]);
        $this->ar(['name' => 'فرع العلامة الثالثة', 'phone' => $phone]);

        $this->runPipeline();
        $this->artisan('legacy:suggest-pairs')->assertSuccessful(); // default needs 2 distinct signals

        $this->assertSame(3, Provider::query()->count());
        $this->assertSame(['unpaired'], LegacyIdMap::query()->pluck('status')->unique()->all());
        $this->assertSame(0, DB::table('legacy_pair_suggestions')->count(), 'phone alone never produces a suggestion');
        $this->assertSame(3, Provider::query()->whereNull('deleted_at')->count());
    }

    public function test_featured_entries_are_brand_level_without_logos_or_provider_links(): void
    {
        $this->top(['name_english' => 'Brand A', 'name_arabic' => 'العلامة أ', 'type_en' => 'laboratory', 'type_ar' => 'laboratory', 'logo_path' => 'secret-logo.png']);
        $this->top(['type_en' => null, 'type_ar' => null]);
        $this->top(['type_en' => 'laboratory', 'type_ar' => 'pharmacy']); // contradictory types

        $batch = $this->runPipeline();

        $this->assertSame(2, FeaturedProvider::query()->count());
        $f = FeaturedProvider::query()->orderBy('id')->get();
        $this->assertSame(['Brand A', 'العلامة أ', null, 0, true], [$f[0]->name_en, $f->first()->name_ar, $f[0]->logo_path, $f[0]->sort_order, $f[0]->is_active]);
        $this->assertSame(ProviderType::query()->where('key', 'laboratory')->value('id'), $f[0]->provider_type_id);
        $this->assertNull($f[1]->provider_type_id, 'a blank type stays blank');
        $this->assertSame(0, Provider::query()->count());
        $this->assertSame(['type_conflict'], LegacyStagingRow::query()->where('state', 'needs_review')->pluck('reason')->all());

        $this->assertSame('secret-logo.png', LegacyStagingRow::query()->firstOrFail()->payload['logo_path'], 'the raw reference stays in staging only');
        $map = LegacyIdMap::query()->where('source_table', 'top_providers')->firstOrFail();
        $this->assertSame([null, $f[0]->id, $batch->id], [$map->provider_id, $map->featured_provider_id, $map->migration_batch_id]);
    }

    public function test_applying_again_is_idempotent_and_deterministic(): void
    {
        $this->en();
        $this->ar();
        $this->top();
        $admin = $this->admin();
        $this->runPipeline($admin);
        $counts = $this->targetCounts();
        $states = LegacyStagingRow::query()->orderBy('id')->pluck('state')->all();

        $this->artisan('legacy:apply', ['--actor' => $admin->email])->assertSuccessful();
        $this->artisan('legacy:apply', ['--actor' => $admin->email])->assertSuccessful();
        // An applied snapshot cannot be staged again (that would hide drift): roll back first.
        $this->artisan('legacy:stage', ['--snapshot-sha256' => self::SHA])->expectsOutputToContain('already applied')->assertFailed();

        $this->assertSame($counts, $this->targetCounts());
        $this->assertSame($states, LegacyStagingRow::query()->orderBy('id')->pluck('state')->all());
        $this->assertSame(1, LegacyMigrationBatch::query()->count());
    }

    public function test_a_second_identical_run_in_a_clean_database_produces_identical_results(): void
    {
        $this->en(['phone' => '0100 000 0001', 'map_url' => 'https://m.test/?q=30.1,31.2']);
        $this->en(['type' => 'Laboratories']);
        $this->ar();
        $this->top();

        $this->runPipeline();
        $first = $this->fingerprint();

        // roll the batch back (clean target) and run the same pipeline again.
        $this->artisan('legacy:rollback', ['batch' => (string) LegacyMigrationBatch::query()->firstOrFail()->uuid])->assertSuccessful();
        $this->assertSame(0, Provider::query()->count());
        $this->runPipeline();

        $this->assertSame($first, $this->fingerprint());
    }

    /** Content (no ids/timestamps) of everything the pipeline produced, in a stable order. */
    private function fingerprint(): string
    {
        $latest = LegacyMigrationBatch::query()->latest('id')->firstOrFail();
        $providers = Provider::query()->with('translations')->orderBy('id')->get()->map(fn (Provider $p) => [
            $p->phone, $p->map_url, (string) $p->latitude, (string) $p->longitude, $p->type?->key,
            $p->translations->map(fn ($t) => [$t->locale, $t->name, $t->address])->all(),
        ])->all();
        $states = LegacyStagingRow::query()->where('migration_batch_id', $latest->id)->orderBy('source_table')->orderBy('source_id')
            ->get(['source_table', 'source_id', 'row_hash', 'state', 'reason'])->toArray();

        return hash('sha256', json_encode([$providers, $states, FeaturedProvider::query()->orderBy('id')->pluck('name_en')->all()]));
    }

    public function test_dry_run_apply_writes_nothing(): void
    {
        $this->en();
        $this->en(['type' => 'Nonsense']);
        $this->ar();
        $this->top();
        $this->stage();
        $before = $this->targetCounts();

        $this->artisan('legacy:apply', ['--dry-run' => true])->expectsOutputToContain('Dry run: nothing was written.')->assertSuccessful();

        $this->assertSame($before, $this->targetCounts());
        $this->assertSame(0, Provider::query()->count());
        $this->assertSame(['staged'], LegacyStagingRow::query()->pluck('state')->unique()->all());
        $this->assertSame('staged', LegacyMigrationBatch::query()->firstOrFail()->status);
        $this->assertSame(0, AuditEvent::query()->where('action', 'provider.created')->count());
    }

    public function test_applying_requires_an_active_admin_actor(): void
    {
        $this->en();
        $this->stage();
        $editor = User::factory()->editor()->create();
        $inactiveAdmin = User::factory()->admin()->inactive()->create();

        $this->artisan('legacy:apply')->expectsOutputToContain('requires --actor')->assertFailed();
        $this->artisan('legacy:apply', ['--actor' => 'nobody@example.test'])->assertFailed();
        $this->artisan('legacy:apply', ['--actor' => $editor->email])->assertFailed();
        $this->artisan('legacy:apply', ['--actor' => $inactiveAdmin->email])->assertFailed();

        $this->assertSame(0, Provider::query()->count());
    }

    public function test_provider_writes_go_through_provider_service_and_are_audited_without_values(): void
    {
        $this->en(['name' => 'Highly Identifiable Clinic Name', 'phone' => '0100 777 8888']);
        $admin = $this->admin();

        $this->runPipeline($admin);

        $created = AuditEvent::query()->where('action', 'provider.created')->get();
        $this->assertCount(1, $created);
        $this->assertSame($admin->id, $created[0]->actor_id);
        $this->assertSame($admin->id, Provider::query()->firstOrFail()->created_by);
        $this->assertSame(1, Provider::query()->firstOrFail()->revision);

        $audit = json_encode(AuditEvent::query()->get()->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Highly Identifiable', $audit);
        $this->assertStringNotContainsString('777 8888', $audit);
        $batchEvent = AuditEvent::query()->where('action', 'migration.batch.applied')->firstOrFail();
        $this->assertSame(1, $batchEvent->metadata['mapped']);
    }

    public function test_rows_failing_provider_validation_are_held_for_review_not_truncated_or_fixed(): void
    {
        $this->en(['email' => 'not an e-mail', 'name' => 'Row With Bad Email']);
        $this->en(['name' => str_repeat('N', 300)]);
        $this->en(['phone' => str_repeat('9', 501)]);
        $this->en();

        $this->runPipeline();

        $this->assertSame(1, Provider::query()->count());
        $rows = LegacyStagingRow::query()->where('state', 'needs_review')->orderBy('source_id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(
            ['validation_failed:email', 'validation_failed:translations.en.name', 'validation_failed:phone'],
            $rows->pluck('reason')->all(),
        );
        // Reasons name fields only, never values.
        $this->assertStringNotContainsString('Row With Bad Email', $rows->pluck('reason')->implode(' '));
    }

    public function test_apply_never_approves_or_pairs_anything(): void
    {
        $this->en(['id' => 1, 'phone' => '01011111111', 'map_url' => 'https://m.test/?q=30,31']);
        $this->ar(['id' => 1, 'phone' => '01011111111', 'map_url' => 'https://m.test/?q=30,31']);

        $this->runPipeline();
        $this->artisan('legacy:suggest-pairs')->assertSuccessful();

        $this->assertSame(0, LegacyIdMap::query()->where('status', '!=', 'unpaired')->count());
        $this->assertSame(0, DB::table('legacy_pair_suggestions')->where('status', '!=', 'suggested')->count());
        $this->assertSame(2, Provider::query()->whereNull('deleted_at')->count());
    }
}
