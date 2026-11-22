<?php

namespace Tests\Feature\Legacy;

use App\Models\AuditEvent;
use App\Models\FeaturedProvider;
use App\Models\LegacyIdMap;
use App\Models\LegacyMigrationBatch;
use App\Models\LegacyPairSuggestion;
use App\Models\LegacyStagingRow;
use App\Models\Provider;
use App\Models\ProviderTranslation;
use App\Services\Legacy\LegacyReconciler;
use App\Services\Legacy\PairReviewService;
use App\Services\ProviderService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class ReconcileRollbackTest extends LegacyTestCase
{
    private function populate(): void
    {
        $this->en(['id' => 1, 'phone' => '01000000001', 'name' => 'Reportable Clinic Name']);
        $this->ar(['id' => 1, 'phone' => '01000000001', 'name' => 'عيادة للتقرير']);
        $this->en(['id' => 2, 'type' => 'Laboratories']);
        $this->en(['id' => 3, 'name' => '']);
        $this->ar(['id' => 4]);
        $this->top();
    }

    public function test_reconcile_accounts_for_every_source_row(): void
    {
        $this->populate();
        $this->runPipeline();

        $result = app(LegacyReconciler::class)->reconcile(LegacyMigrationBatch::query()->firstOrFail());

        $this->assertTrue($result['ok'], implode(',', $result['discrepancies']));
        $this->assertSame(['providers' => 3, 'arabic_providers' => 2, 'top_providers' => 1], $result['source']);
        $this->assertSame($result['source'], $result['staged']);
        $this->assertSame(6, array_sum($result['staged']));
        $this->assertSame(['mapped' => 1, 'needs_review' => 1, 'rejected' => 1], $result['states']['providers']);
        $this->assertEquals(['type_needs_review' => 1, 'missing_name' => 1], $result['reasons']);

        $this->artisan('legacy:reconcile')->expectsOutputToContain('every source row is accounted for')->assertSuccessful();
    }

    public function test_reconcile_fails_with_a_non_zero_exit_on_any_discrepancy(): void
    {
        $this->populate();
        $batch = $this->runPipeline();

        // 1. a staged row missing
        LegacyStagingRow::query()->where('source_table', 'arabic_providers')->where('source_id', 4)->delete();
        $this->artisan('legacy:reconcile')->expectsOutputToContain('count_mismatch:arabic_providers')->assertFailed();

        // 2. a source row added after staging
        $this->legacy()->table('providers')->insert(['id' => 77, 'name' => 'Late row', 'type' => 'Laboratory', 'address' => 'x']);
        $this->artisan('legacy:reconcile')->expectsOutputToContain('count_mismatch:providers')->assertFailed();
    }

    public function test_reconcile_detects_unprocessed_rows_missing_reasons_and_map_mismatches(): void
    {
        $this->populate();
        $batch = $this->runPipeline();
        $reconciler = app(LegacyReconciler::class);

        LegacyStagingRow::query()->where('source_table', 'top_providers')->update(['state' => 'staged']);
        $this->assertContains('unprocessed_rows:top_providers', $reconciler->reconcile($batch)['discrepancies']);
        LegacyStagingRow::query()->where('source_table', 'top_providers')->update(['state' => 'mapped']);

        LegacyStagingRow::query()->where('state', 'rejected')->update(['reason' => null]);
        $this->assertContains('missing_reason', $reconciler->reconcile($batch)['discrepancies']);
        LegacyStagingRow::query()->where('state', 'rejected')->update(['reason' => 'missing_name']);

        LegacyIdMap::query()->where('source_table', 'providers')->delete();
        $this->assertContains('map_mismatch:providers', $reconciler->reconcile($batch)['discrepancies']);
    }

    public function test_expect_baseline_compares_the_live_source_with_the_recorded_counts(): void
    {
        $this->populate();
        $this->runPipeline();

        $this->artisan('legacy:reconcile', ['--expect-baseline' => true])->expectsOutputToContain('baseline_mismatch:providers')->assertFailed();

        config(['legacy.baseline_counts' => ['providers' => 3, 'arabic_providers' => 2, 'top_providers' => 1, 'types' => 30]]);
        $this->artisan('legacy:reconcile', ['--expect-baseline' => true])->assertSuccessful();
    }

    public function test_the_report_is_written_only_on_request_and_contains_aggregates_only(): void
    {
        $this->populate();
        $batch = $this->runPipeline();
        $this->artisan('legacy:suggest-pairs')->assertSuccessful();

        $this->artisan('legacy:reconcile')->assertSuccessful();
        $this->assertDirectoryDoesNotExist($this->reportsDir, 'no report without --report');

        $this->artisan('legacy:reconcile', ['--report' => true])->assertSuccessful();
        $files = glob($this->reportsDir.'/*.md') ?: [];
        $this->assertCount(1, $files);
        $this->assertSame('reconciliation-19a71fe3-v1-rehearsal.md', basename($files[0]));

        $report = (string) file_get_contents($files[0]);
        $this->assertStringContainsString('| providers | 3 | 3 | 1 | 1 | 1 | 0 |', $report);
        $this->assertStringContainsString('| same_source_id | 1 |', $report);
        $this->assertStringContainsString('| type_needs_review | 1 |', $report);
        $this->assertStringContainsString('| parsed | 0 |', $report);
        // Sanitized: no value of any source row, no uuid.
        foreach ([
            'Reportable Clinic Name', 'عيادة للتقرير', '01000000001', 'Synthetic', 'Cairo', 'Street', 'm.test', '@', 'http', $batch->uuid,
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $report, "report must not contain '{$needle}'");
        }

        $this->artisan('legacy:report', ['--name' => 'custom-name'])->assertSuccessful();
        $this->assertFileExists($this->reportsDir.'/custom-name.md');
    }

    public function test_rollback_after_forward_run_restores_every_target_table_to_empty(): void
    {
        $this->populate();
        $batch = $this->runPipeline();
        $this->artisan('legacy:suggest-pairs')->assertSuccessful();
        $this->assertGreaterThan(0, Provider::query()->count());
        $this->assertSame(1, FeaturedProvider::query()->count());
        $this->assertSame(1, LegacyPairSuggestion::query()->count());

        $this->artisan('legacy:rollback', ['batch' => $batch->uuid])->assertSuccessful();

        $this->assertSame(
            ['providers' => 0, 'provider_translations' => 0, 'featured_providers' => 0, 'legacy_id_maps' => 0, 'legacy_pair_suggestions' => 0],
            $this->targetCounts(),
        );
        $batch->refresh();
        $this->assertSame('rolled_back', $batch->status);
        $this->assertNotNull($batch->rolled_back_at);
        $this->assertSame(1, AuditEvent::query()->where('action', 'migration.batch.rolled_back')->count());
        $this->assertSame(1, DB::table('provider_types')->where('key', 'laboratory')->count(), 'reference data is untouched');

        $this->artisan('legacy:rollback', ['batch' => $batch->uuid])->expectsOutputToContain('already rolled back')->assertFailed();
        $this->artisan('legacy:reconcile')->assertFailed(); // no active batch left
    }

    public function test_rollback_also_removes_merged_providers_and_soft_deleted_archives(): void
    {
        $this->populate();
        $admin = $this->admin();
        $batch = $this->runPipeline($admin);
        $this->artisan('legacy:suggest-pairs')->assertSuccessful();
        app(PairReviewService::class)->approve($admin, LegacyPairSuggestion::query()->firstOrFail(), 'Reviewed in test');
        $this->assertSame(1, Provider::query()->onlyTrashed()->count());

        $this->artisan('legacy:rollback', ['batch' => (string) $batch->id])->assertSuccessful();

        $this->assertSame(0, Provider::query()->withTrashed()->count());
        $this->assertSame(0, ProviderTranslation::query()->count());
    }

    public function test_rollback_refuses_when_providers_were_edited_afterwards_unless_forced(): void
    {
        $this->populate();
        $admin = $this->admin();
        $batch = $this->runPipeline($admin);
        $provider = Provider::query()->firstOrFail();

        app(ProviderService::class)->update($admin, $provider, ['phone' => '01999999999']);
        $counts = $this->targetCounts();

        $this->artisan('legacy:rollback', ['batch' => $batch->uuid])->expectsOutputToContain('edited after the migration')->assertFailed();
        $this->assertSame($counts, $this->targetCounts());
        $this->assertSame('applied', $batch->fresh()->status);

        $this->artisan('legacy:rollback', ['batch' => $batch->uuid, '--force' => true])->assertSuccessful();
        $this->assertSame(0, Provider::query()->withTrashed()->count());
    }

    public function test_rollback_refuses_after_a_human_delete_or_restore_too(): void
    {
        $this->populate();
        $admin = $this->admin();
        $batch = $this->runPipeline($admin);

        app(ProviderService::class)->delete($admin, Provider::query()->firstOrFail());

        $this->artisan('legacy:rollback', ['batch' => $batch->uuid])->assertFailed();
        $this->assertSame(1, Provider::query()->onlyTrashed()->count());
    }

    public function test_pipeline_review_merges_do_not_count_as_later_edits(): void
    {
        $this->populate();
        $admin = $this->admin();
        $batch = $this->runPipeline($admin);
        $this->artisan('legacy:suggest-pairs')->assertSuccessful();
        $service = app(PairReviewService::class);
        $service->approve($admin, LegacyPairSuggestion::query()->firstOrFail(), 'Reviewed in test');
        $service->undo($admin, LegacyPairSuggestion::query()->firstOrFail(), 'Reviewed in test');
        $service->approve($admin, LegacyPairSuggestion::query()->firstOrFail(), 'Reviewed in test');

        $this->artisan('legacy:rollback', ['batch' => $batch->uuid, '--dry-run' => true])->assertSuccessful();
    }

    public function test_rollback_dry_run_deletes_nothing(): void
    {
        $this->populate();
        $batch = $this->runPipeline();
        $before = $this->targetCounts();

        $this->artisan('legacy:rollback', ['batch' => $batch->uuid, '--dry-run' => true])->expectsOutputToContain('Dry run: nothing was deleted.')->assertSuccessful();

        $this->assertSame($before, $this->targetCounts());
        $this->assertSame('applied', $batch->fresh()->status);
    }

    public function test_rollback_is_a_single_transaction(): void
    {
        $this->populate();
        $batch = $this->runPipeline();
        $before = $this->targetCounts();

        // Make the last step of the rollback fail: the whole thing must roll back.
        LegacyMigrationBatch::saving(function () {
            throw new \RuntimeException('simulated failure');
        });

        try {
            Artisan::call('legacy:rollback', ['batch' => $batch->uuid]);
            $this->fail('expected the simulated failure');
        } catch (\RuntimeException) {
        } finally {
            LegacyMigrationBatch::flushEventListeners();
        }

        $this->assertSame($before, $this->targetCounts());
    }

    public function test_unknown_batches_are_reported_without_side_effects(): void
    {
        $this->artisan('legacy:rollback', ['batch' => 'does-not-exist'])->expectsOutputToContain('No such migration batch')->assertFailed();
        $this->artisan('legacy:apply')->expectsOutputToContain('No active migration batch')->assertFailed();
    }
}
