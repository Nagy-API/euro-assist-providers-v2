<?php

namespace Tests\Feature\Legacy;

use App\Models\AuditEvent;
use App\Models\LegacyIdMap;
use App\Models\LegacyMigrationBatch;
use App\Models\LegacyPairSuggestion;
use App\Models\LegacyRowDecision;
use App\Models\LegacyStagingRow;
use App\Models\LegacyTypeMapping;
use App\Models\Provider;
use App\Models\ProviderTranslation;
use App\Models\ProviderType;
use App\Models\User;
use App\Services\DashboardStats;
use App\Services\Legacy\LegacyException;
use App\Services\Legacy\PairReviewService;
use App\Services\Legacy\ReviewManifestApplier;
use App\Services\Legacy\TypeMapper;
use App\Services\ProviderService;
use Illuminate\Validation\ValidationException;

/**
 * legacy:apply-review-manifest (ADR-017) on SYNTHETIC data: a manifest proposal is applied only
 * when it re-verifies against V2, and anything doubtful is left exactly as it was.
 */
class ReviewManifestTest extends LegacyTestCase
{
    private User $actor;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /**
     * Three branches of one brand, each present once per language with its own unique map link.
     *
     * @param  array<int, array{0: array<string, mixed>, 1: array<string, mixed>}>  $overrides  per branch number 1..3
     * @return list<array{0: int, 1: int}>
     */
    private function branches(array $overrides = []): array
    {
        $out = [];
        for ($i = 1; $i <= 3; $i++) {
            [$en, $ar] = $overrides[$i] ?? [[], []];
            $common = ['phone' => '0123456'.$i, 'map_url' => "https://maps.example.test/?q=30.0{$i},31.0{$i}"];
            $e = $this->en($en + $common + ['id' => 100 + $i, 'name' => 'Synthetic Lab', 'type' => 'Laboratory', 'address' => "{$i}0 Example Street", 'city' => 'Cairo', 'district' => 'Nasr City']);
            $a = $this->ar($ar + $common + ['id' => 200 + $i, 'name' => 'معمل تجريبي', 'type' => 'معمل تحاليل', 'address' => "{$i}0 شارع المثال", 'city' => 'القاهرة', 'district' => 'مدينة نصر']);
            $out[] = [$e, $a];
        }

        return $out;
    }

    private function prepare(): void
    {
        $this->actor = $this->admin();
        $this->runPipeline($this->actor);
    }

    private function payload(string $table, int $id): array
    {
        return (array) LegacyStagingRow::query()->where('source_table', $table)->where('source_id', $id)->firstOrFail()->payload;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $pairs
     * @param  array<string, mixed>  $extra
     */
    private function manifest(array $pairs, array $extra = []): string
    {
        $strict = [];
        foreach ($pairs as [$e, $a]) {
            $ep = $this->payload('providers', $e);
            $ap = $this->payload('arabic_providers', $a);
            $strict[] = [
                'en_source_table' => 'providers', 'en_source_id' => $e, 'ar_source_table' => 'arabic_providers', 'ar_source_id' => $a,
                'en_row_sha256' => ReviewManifestApplier::rowFingerprint($ep), 'ar_row_sha256' => ReviewManifestApplier::rowFingerprint($ap),
                'shared_map_sha256' => hash('sha256', (string) $ep['map_url']), 'type_key' => 'laboratory',
                'status' => ReviewManifestApplier::PAIR_STATUS,
            ];
        }
        $manifest = $extra + [
            'schema_version' => '1.0', 'source_sha256' => self::SHA, 'strict_pairs' => $strict,
            'type_alias_decisions' => [], 'eye_center_decisions' => [], 'repair_map_urls' => [],
        ];
        $file = tempnam(sys_get_temp_dir(), 'manifest-test-');
        file_put_contents($file, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->files[] = $file;

        return $file;
    }

    private function apply(string $file, bool $dryRun = false): array
    {
        $batch = LegacyMigrationBatch::query()->latest('id')->firstOrFail();

        return app(ReviewManifestApplier::class)->run($batch, $file, $dryRun ? null : $this->actor, $dryRun);
    }

    /** @return array<string, string> */
    private function outcomes(array $result): array
    {
        $out = [];
        foreach ($result['pairs'] as $p) {
            $out[$p['en_source_id'].'/'.$p['ar_source_id']] = $p['outcome'].($p['codes'] === [] ? '' : ':'.implode(',', $p['codes']));
        }

        return $out;
    }

    public function test_verified_pairs_are_applied_through_the_audited_review_service_and_can_be_undone(): void
    {
        $pairs = $this->branches();
        $this->prepare();
        $source = $this->sourceSnapshot();
        $payloads = LegacyStagingRow::query()->orderBy('id')->pluck('payload', 'id')->all();
        $file = $this->manifest($pairs);

        $dry = $this->apply($file, dryRun: true);
        $this->assertSame(3, $dry['counts']['pairs_applied']);
        $this->assertSame(6, Provider::query()->count(), 'a dry run writes nothing');
        $this->assertSame(0, LegacyPairSuggestion::query()->where('status', 'approved')->count());

        $result = $this->apply($file);
        $this->assertSame(['101/201' => 'applied', '102/202' => 'applied', '103/203' => 'applied'], $this->outcomes($result));

        $this->assertSame(3, Provider::query()->count());
        $this->assertSame(3, Provider::onlyTrashed()->count(), 'Arabic-side providers are soft-deleted, never removed');
        $this->assertSame(6, ProviderTranslation::query()->count(), 'no translation is lost');
        foreach (Provider::query()->with('translations')->get() as $p) {
            $this->assertEqualsCanonicalizing(['en', 'ar'], $p->translations->pluck('locale')->all());
        }
        $this->assertSame(6, LegacyIdMap::query()->where('status', 'approved')->count());
        foreach (LegacyIdMap::query()->where('source_table', 'arabic_providers')->get() as $m) {
            $this->assertSame([], $m->review_evidence['discarded_ar_values'], 'nothing is hidden by the merge');
        }
        $s = LegacyPairSuggestion::query()->firstOrFail();
        $this->assertContains(ReviewManifestApplier::SIGNAL_MANIFEST, $s->signals);
        $this->assertStringContainsString('Owner review manifest', $s->review_history[0]['reason']);
        $this->assertSame($this->actor->id, $s->reviewed_by);
        $this->assertSame(3, AuditEvent::query()->where('action', 'migration.pair.approved')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'migration.manifest.applied')->count());

        // The Arabic-side shells are merge shells, not deleted providers (ADR-017).
        foreach (Provider::onlyTrashed()->get() as $shell) {
            $this->assertNotNull($shell->merged_into_id);
            $this->assertFalse($this->actor->can('restore', $shell));
        }
        $this->assertSame(0, app(DashboardStats::class)->counters(true)['deleted']);
        $shell = Provider::onlyTrashed()->firstOrFail();
        $this->actingAs($this->actor)->get(route('providers.show', $shell->id))->assertRedirect(route('providers.show', $shell->merged_into_id));

        // Source and raw staging untouched.
        $this->assertSame($source, $this->sourceSnapshot());
        $this->assertSame($payloads, LegacyStagingRow::query()->orderBy('id')->pluck('payload', 'id')->all());

        // Idempotent.
        $again = $this->apply($file);
        $this->assertSame(3, $again['counts']['pairs_already_applied']);
        $this->assertSame(3, Provider::query()->count());

        // Reversible with the existing undo.
        app(PairReviewService::class)->undo($this->actor, $s, 'Undo rehearsal on synthetic data');
        $this->assertSame(4, Provider::query()->count());
        $this->assertSame(2, Provider::onlyTrashed()->count());
        $this->assertSame(2, Provider::withTrashed()->whereNotNull('merged_into_id')->count(), 'the undone shell is no longer marked as merged');
    }

    public function test_a_pair_a_human_already_rejected_is_never_overridden_and_the_run_completes(): void
    {
        $pairs = $this->branches();
        $this->prepare();
        $e = LegacyIdMap::query()->where('source_table', 'providers')->where('source_id', 102)->firstOrFail();
        $a = LegacyIdMap::query()->where('source_table', 'arabic_providers')->where('source_id', 202)->firstOrFail();
        $s = LegacyPairSuggestion::query()->create([
            'migration_batch_id' => $e->migration_batch_id, 'left_map_id' => $e->id, 'right_map_id' => $a->id,
            'signals' => ['same_map_url'], 'signal_count' => 1, 'status' => LegacyPairSuggestion::STATUS_SUGGESTED,
        ]);
        app(PairReviewService::class)->reject($this->actor, $s, 'Reviewer says these are different branches');
        $file = $this->manifest($pairs);

        $this->assertSame('held:suggestion_already_reviewed:rejected', $this->outcomes($this->apply($file, dryRun: true))['102/202']);
        $result = $this->apply($file);

        $this->assertSame('held:suggestion_already_reviewed:rejected', $this->outcomes($result)['102/202']);
        $this->assertSame(2, $result['counts']['pairs_applied'], 'the other pairs are still applied');
        $this->assertSame(1, AuditEvent::query()->where('action', 'migration.manifest.applied')->count());
    }

    public function test_alias_corroboration_uses_the_arabic_rows_own_label_even_after_pairing(): void
    {
        // Branch 1's Arabic row is labelled pharmacy: it must contradict the alias, also once paired.
        $pairs = $this->branches([1 => [['type' => 'Laboratories'], ['type' => 'صيدلية']], 2 => [['type' => 'Laboratories'], []]]);
        $this->actor = $this->admin();
        $this->stage();
        $this->artisan('legacy:apply', ['--actor' => $this->actor->email, '--accept-provisional-types' => true])->assertSuccessful();

        $file = $this->manifest($pairs, ['type_alias_decisions' => [[
            'source_label' => 'laboratories', 'target_type_key' => 'laboratory', 'source_count' => 2,
            'decision' => ReviewManifestApplier::ALIAS_DECISION, 'basis' => 'x',
        ]]]);
        $result = $this->apply($file);

        $this->assertSame('held', $result['aliases'][0]['outcome']);
        $this->assertContains('arabic_contradiction', $result['aliases'][0]['codes']);
        $this->assertSame(TypeMapper::PROVISIONAL, LegacyTypeMapping::query()->where('raw_value', 'Laboratories')->value('status'));
    }

    public function test_a_malformed_manifest_is_refused_before_anything_is_written(): void
    {
        $pairs = $this->branches();
        $this->prepare();
        $file = $this->manifest($pairs, ['repair_map_urls' => ['not an object']]);

        try {
            $this->apply($file);
            $this->fail('a malformed manifest must be refused');
        } catch (LegacyException $e) {
            $this->assertStringContainsString('repair_map_urls[0]', $e->getMessage());
        }
        $this->assertSame(6, Provider::query()->count());
        $this->assertSame(0, LegacyPairSuggestion::query()->count());
    }

    public function test_a_merge_shell_cannot_be_restored_through_the_service(): void
    {
        $pairs = $this->branches();
        $this->prepare();
        $this->apply($this->manifest($pairs));
        $shell = Provider::onlyTrashed()->firstOrFail();

        $this->expectException(ValidationException::class);
        app(ProviderService::class)->restore($this->actor, $shell);
    }

    public function test_conflicting_canonical_values_keep_both_records(): void
    {
        $pairs = $this->branches([2 => [['email' => 'en@example.test'], ['email' => 'ar@example.test']]]);
        $this->prepare();

        $result = $this->apply($this->manifest($pairs));

        $this->assertSame('held:canonical_conflict:email', $this->outcomes($result)['102/202']);
        $this->assertSame(2, $result['counts']['pairs_applied']);
        $this->assertSame(4, Provider::query()->count());
    }

    public function test_a_map_link_shared_with_a_third_record_is_not_branch_evidence(): void
    {
        $pairs = $this->branches();
        $this->en(['id' => 150, 'name' => 'Other Clinic', 'map_url' => 'https://maps.example.test/?q=30.01,31.01', 'phone' => '0999999']);
        $this->prepare();

        $out = $this->outcomes($this->apply($this->manifest($pairs)));

        $this->assertStringStartsWith('held:', $out['101/201']);
        $this->assertStringContainsString('map_url_shared', $out['101/201']);
        $this->assertStringContainsString('coordinates_shared', $out['101/201']);
    }

    public function test_a_changed_source_row_is_refused(): void
    {
        $pairs = $this->branches();
        $this->prepare();
        $file = $this->manifest($pairs);
        $m = json_decode((string) file_get_contents($file), true);
        $m['strict_pairs'][0]['en_row_sha256'] = str_repeat('0', 64);
        file_put_contents($file, json_encode($m));

        $this->assertSame('held:source_row_changed', $this->outcomes($this->apply($file))['101/201']);
    }

    public function test_unrelated_districts_and_disjoint_address_numbers_are_held(): void
    {
        $pairs = $this->branches([
            1 => [['district' => 'Maadi'], ['district' => 'العباسية']],
            3 => [['address' => '15 Example Street'], ['address' => '99 شارع المثال']],
        ]);
        $this->prepare();

        $out = $this->outcomes($this->apply($this->manifest($pairs)));

        $this->assertSame('held:district_not_corroborated', $out['101/201']);
        $this->assertSame('held:address_numbers_disjoint', $out['103/203']);
    }

    public function test_type_alias_needs_arabic_corroboration_and_releases_provisional_rows(): void
    {
        $pairs = $this->branches([1 => [['type' => 'Laboratories'], []], 2 => [['type' => 'Laboratories'], []]]);
        $this->actor = $this->admin();
        $this->stage();
        $this->artisan('legacy:apply', ['--actor' => $this->actor->email, '--accept-provisional-types' => true])->assertSuccessful();
        $this->assertSame(2, LegacyStagingRow::query()->where('reason', 'provisional_type')->count());

        $file = $this->manifest($pairs, ['type_alias_decisions' => [[
            'source_label' => 'laboratories', 'target_type_key' => 'laboratory', 'source_count' => 2,
            'decision' => ReviewManifestApplier::ALIAS_DECISION, 'basis' => 'Exact plural.',
        ]]]);
        $result = $this->apply($file);

        $this->assertSame('approved', $result['aliases'][0]['outcome']);
        $this->assertSame(2, $result['aliases'][0]['corroborated_pairs']);
        $this->assertSame(0, LegacyStagingRow::query()->where('reason', 'provisional_type')->count());
        $this->assertSame(2, LegacyStagingRow::query()->where('reason', 'type_alias_approved')->count());
        $mapping = LegacyTypeMapping::query()->where('raw_value', 'Laboratories')->firstOrFail();
        $this->assertSame(TypeMapper::APPROVED, $mapping->status);
        $this->assertStringContainsString('Owner D3 decision', (string) $mapping->note);
        $this->assertSame('Laboratories', $this->payload('providers', 101)['type'], 'the raw label is preserved');
    }

    public function test_alias_with_a_changed_source_count_is_held(): void
    {
        $pairs = $this->branches([1 => [['type' => 'Laboratories'], []]]);
        $this->actor = $this->admin();
        $this->stage();
        $this->artisan('legacy:apply', ['--actor' => $this->actor->email, '--accept-provisional-types' => true])->assertSuccessful();

        $file = $this->manifest($pairs, ['type_alias_decisions' => [[
            'source_label' => 'laboratories', 'target_type_key' => 'laboratory', 'source_count' => 5,
            'decision' => ReviewManifestApplier::ALIAS_DECISION, 'basis' => 'x',
        ]]]);
        $result = $this->apply($file);

        $this->assertSame('held', $result['aliases'][0]['outcome']);
        $this->assertContains('source_count_changed', $result['aliases'][0]['codes']);
        $this->assertSame(TypeMapper::PROVISIONAL, LegacyTypeMapping::query()->where('raw_value', 'Laboratories')->value('status'));
    }

    public function test_eye_center_row_decision_maps_one_row_and_keeps_the_raw_type(): void
    {
        $this->en(['id' => 301, 'type' => 'Eye Center', 'map_url' => 'https://maps.example.test/?q=29.1,31.1']);
        $this->ar(['id' => 401, 'type' => 'بصريات', 'map_url' => 'https://maps.example.test/?q=29.1,31.1']);
        $this->en(['id' => 302, 'type' => 'Eye Center', 'map_url' => 'https://maps.example.test/?q=29.2,31.2']);
        $this->prepare();
        $this->assertSame(2, LegacyStagingRow::query()->where('reason', 'type_needs_review')->count());

        $file = $this->manifest([], ['eye_center_decisions' => [
            ['en_source_id' => 301, 'en_row_sha256' => ReviewManifestApplier::rowFingerprint($this->payload('providers', 301)), 'matching_ar_source_id' => 401,
                'ar_type_raw' => 'بصريات', 'status' => ReviewManifestApplier::EYE_PROPOSAL, 'proposed_type_key' => 'optics'],
            ['en_source_id' => 302, 'en_row_sha256' => 'x', 'matching_ar_source_id' => null, 'ar_type_raw' => null,
                'status' => 'HOLD_DUPLICATE_EN_SOURCE', 'proposed_type_key' => null],
        ]]);
        $result = $this->apply($file);

        $this->assertSame(['recorded', 'held_by_manifest'], array_column($result['row_decisions'], 'outcome'));
        $row = LegacyStagingRow::query()->where('source_id', 301)->where('source_table', 'providers')->firstOrFail();
        $this->assertSame('mapped', $row->state);
        $this->assertSame('row_decision:type_key', $row->reason);
        $this->assertSame('Eye Center', $row->payload['type']);
        $optics = ProviderType::query()->where('key', 'optics')->value('id');
        $this->assertSame($optics, LegacyIdMap::query()->where('source_table', 'providers')->where('source_id', 301)->firstOrFail()->provider?->provider_type_id);
        $this->assertSame('needs_review', LegacyStagingRow::query()->where('source_id', 302)->where('source_table', 'providers')->value('state'), 'the held row stays visible for review');
        $this->assertSame(1, LegacyRowDecision::query()->count());
    }

    public function test_malformed_map_link_is_repaired_in_the_target_only(): void
    {
        $raw = 'https://maps.example.test/?q=30.5 https://maps.example.test/?q=30.5,31.5';
        $this->en(['id' => 501, 'map_url' => $raw]);
        $this->prepare();
        $this->assertSame('validation_failed:map_url', LegacyStagingRow::query()->where('source_id', 501)->value('reason'));

        $file = $this->manifest([], ['repair_map_urls' => [[
            'source_table' => 'providers', 'source_id' => 501,
            'source_row_sha256' => ReviewManifestApplier::rowFingerprint($this->payload('providers', 501)),
            'original_map_url_sha256' => hash('sha256', $raw), 'repaired_map_url' => 'https://maps.example.test/?q=30.5,31.5',
            'status' => ReviewManifestApplier::MAP_REPAIR,
        ]]]);
        $result = $this->apply($file);

        $this->assertSame('recorded', $result['row_decisions'][0]['outcome']);
        $provider = LegacyIdMap::query()->where('source_table', 'providers')->where('source_id', 501)->firstOrFail()->provider;
        $this->assertSame('https://maps.example.test/?q=30.5,31.5', $provider?->map_url);
        $this->assertEqualsWithDelta(30.5, (float) $provider?->latitude, 0.0001);
        $this->assertSame($raw, $this->payload('providers', 501)['map_url'], 'the raw value stays in staging');
        $this->assertSame(hash('sha256', $raw), LegacyRowDecision::query()->value('original_sha256'));
    }

    public function test_map_repair_with_an_unexpected_raw_value_is_refused(): void
    {
        $this->en(['id' => 502, 'map_url' => 'https://a.example.test/x https://b.example.test/y https://c.example.test/z']);
        $this->prepare();
        $file = $this->manifest([], ['repair_map_urls' => [[
            'source_table' => 'providers', 'source_id' => 502,
            'source_row_sha256' => ReviewManifestApplier::rowFingerprint($this->payload('providers', 502)),
            'original_map_url_sha256' => hash('sha256', 'https://a.example.test/x https://b.example.test/y https://c.example.test/z'),
            'repaired_map_url' => 'https://b.example.test/y', 'status' => ReviewManifestApplier::MAP_REPAIR,
        ]]]);

        $result = $this->apply($file);

        $this->assertSame('held', $result['row_decisions'][0]['outcome']);
        $this->assertContains('unexpected_split', $result['row_decisions'][0]['codes']);
        $this->assertSame(0, LegacyRowDecision::query()->count());
    }

    public function test_command_refuses_unsafe_use(): void
    {
        $pairs = $this->branches();
        $this->prepare();
        $file = $this->manifest($pairs);

        $this->artisan('legacy:apply-review-manifest', ['manifest' => $file])->assertFailed();
        $this->artisan('legacy:apply-review-manifest', ['manifest' => $file, '--actor' => $this->actor->email, '--expect-sha256' => str_repeat('a', 64)])->assertFailed();
        $this->artisan('legacy:apply-review-manifest', ['manifest' => $file, '--dry-run' => true, '--report' => base_path('storage/review-report.json')])->assertFailed();

        $viewer = User::factory()->create();
        $this->artisan('legacy:apply-review-manifest', ['manifest' => $file, '--actor' => $viewer->email])->assertFailed();

        $other = $this->manifest($pairs, ['source_sha256' => str_repeat('b', 64)]);
        $this->artisan('legacy:apply-review-manifest', ['manifest' => $other, '--dry-run' => true])->assertFailed();

        $this->assertSame(6, Provider::query()->count(), 'nothing was applied by a refused run');

        $report = sys_get_temp_dir().DIRECTORY_SEPARATOR.'manifest-report-'.bin2hex(random_bytes(3)).'.json';
        $this->files[] = $report;
        $this->artisan('legacy:apply-review-manifest', ['manifest' => $file, '--actor' => $this->actor->email, '--expect-sha256' => hash_file('sha256', $file), '--report' => $report])->assertSuccessful();
        $this->assertSame(3, Provider::query()->count());
        $this->assertStringNotContainsString('Synthetic Lab', (string) file_get_contents($report), 'the report carries ids and codes, never provider values');
    }

    public function test_place_skeletons_match_transliterations(): void
    {
        $this->assertSame(ReviewManifestApplier::englishSkeleton('kom ombo'), ReviewManifestApplier::arabicSkeleton('كوم امبو'));
        $this->assertNotSame(ReviewManifestApplier::englishSkeleton('new damietta'), ReviewManifestApplier::arabicSkeleton('فارسكور'));
    }

    public function test_legacy_source_connection_reads_timestamps_in_utc(): void
    {
        // The frozen dump stores TIMESTAMPs in UTC; a SYSTEM-zone session shifted staged values.
        $config = require base_path('config/database.php');
        $this->assertSame('+00:00', $config['connections']['legacy_audit']['timezone'] ?? null);
    }
}
