<?php

namespace Tests\Feature\Legacy;

use App\Models\LegacyMigrationBatch;
use App\Models\LegacyStagingRow;
use App\Services\Legacy\LegacyException;
use App\Services\Legacy\LegacyGuard;
use App\Services\Legacy\LegacyStager;

class StagingTest extends LegacyTestCase
{
    private function seedSource(): void
    {
        $this->en();
        $this->en(['type' => 'Laboratory ']);
        $this->ar();
        $this->top();
    }

    public function test_staging_copies_every_source_row_with_hash_and_batch_metadata(): void
    {
        $this->seedSource();

        $this->artisan('legacy:stage', ['--snapshot-sha256' => self::SHA])->assertSuccessful();

        $batch = LegacyMigrationBatch::query()->firstOrFail();
        $this->assertSame([self::SHA, 'v1', 'staged', 1], [$batch->snapshot_sha256, $batch->mapping_version, $batch->status, (int) $batch->is_rehearsal]);
        $this->assertSame(['providers' => 2, 'arabic_providers' => 1, 'top_providers' => 1], $batch->counts['source']);
        $this->assertSame(4, LegacyStagingRow::query()->count());
        $this->assertSame(['en', 'en', 'ar', null], LegacyStagingRow::query()->orderBy('id')->pluck('source_locale')->all());
        $this->assertSame([4], [LegacyStagingRow::query()->where('state', 'staged')->count()]);

        $row = LegacyStagingRow::query()->where('source_table', 'providers')->where('source_id', 1)->firstOrFail();
        $this->assertSame(64, strlen($row->row_hash));
        $this->assertSame($row->row_hash, LegacyStager::rowHash((array) $this->legacy()->table('providers')->where('id', 1)->first()));
        $this->assertSame('Laboratory ', LegacyStagingRow::query()->where('source_table', 'providers')->where('source_id', 2)->firstOrFail()->payload['type'], 'staging keeps the raw value untouched');
    }

    public function test_staging_is_idempotent_and_deterministic(): void
    {
        $this->seedSource();

        $this->stage();
        $first = LegacyStagingRow::query()->orderBy('id')->get(['source_table', 'source_id', 'row_hash', 'state'])->toArray();
        $this->stage();
        $this->stage();

        $this->assertSame(1, LegacyMigrationBatch::query()->count(), 're-staging reuses the open batch');
        $this->assertSame(4, LegacyStagingRow::query()->count(), 'no duplicate staging rows');
        $this->assertSame($first, LegacyStagingRow::query()->orderBy('id')->get(['source_table', 'source_id', 'row_hash', 'state'])->toArray());
    }

    public function test_restaging_detects_source_changes_by_row_hash_and_reopens_the_row(): void
    {
        $this->seedSource();
        $this->stage();
        $before = LegacyStagingRow::query()->where('source_table', 'providers')->where('source_id', 1)->firstOrFail();
        $unchangedBefore = LegacyStagingRow::query()->where('source_table', 'arabic_providers')->firstOrFail()->row_hash;

        $this->legacy()->table('providers')->where('id', 1)->update(['phone' => '0199999999']);
        $this->legacy()->table('arabic_providers')->insert(['id' => 99, 'name' => 'New Arabic Row', 'type' => 'معمل تحاليل', 'address' => 'x']);

        $this->artisan('legacy:stage', ['--snapshot-sha256' => self::SHA])
            ->expectsOutputToContain('providers')->assertSuccessful();

        $after = LegacyStagingRow::query()->where('source_table', 'providers')->where('source_id', 1)->firstOrFail();
        $this->assertNotSame($before->row_hash, $after->row_hash);
        $this->assertSame('0199999999', $after->payload['phone']);
        $this->assertSame($unchangedBefore, LegacyStagingRow::query()->where('source_table', 'arabic_providers')->where('source_id', '!=', 99)->firstOrFail()->row_hash);
        $this->assertSame(5, LegacyStagingRow::query()->count());
    }

    public function test_dry_run_writes_nothing_and_reports_counts(): void
    {
        $this->seedSource();

        $this->artisan('legacy:stage', ['--snapshot-sha256' => self::SHA, '--dry-run' => true])
            ->expectsOutputToContain('Dry run: nothing was written.')->assertSuccessful();

        $this->assertSame(0, LegacyMigrationBatch::query()->count());
        $this->assertSame(0, LegacyStagingRow::query()->count());
    }

    public function test_dry_run_against_an_existing_batch_does_not_modify_it(): void
    {
        $this->seedSource();
        $this->stage();
        $this->legacy()->table('providers')->where('id', 1)->update(['phone' => '0188888888']);
        $hashes = LegacyStagingRow::query()->orderBy('id')->pluck('row_hash')->all();

        $this->artisan('legacy:stage', ['--snapshot-sha256' => self::SHA, '--dry-run' => true])->assertSuccessful();

        $this->assertSame($hashes, LegacyStagingRow::query()->orderBy('id')->pluck('row_hash')->all());
    }

    public function test_a_wrong_or_missing_snapshot_checksum_is_refused_before_anything_is_read(): void
    {
        $this->seedSource();

        $this->artisan('legacy:stage', ['--snapshot-sha256' => str_repeat('0', 64)])
            ->expectsOutputToContain('does not match the recorded value')->assertFailed();
        $this->artisan('legacy:stage')->assertFailed();
        $this->artisan('legacy:stage', ['--snapshot-sha256' => strtoupper(substr(self::SHA, 0, 63)).'0'])->assertFailed();

        $this->assertSame(0, LegacyMigrationBatch::query()->count());
        $this->assertSame(0, LegacyStagingRow::query()->count());
    }

    public function test_the_checksum_comparison_is_case_insensitive_for_the_recorded_value(): void
    {
        $this->seedSource();

        $this->artisan('legacy:stage', ['--snapshot-sha256' => strtoupper(self::SHA)])->assertSuccessful();
    }

    public function test_every_legacy_command_refuses_to_run_in_production(): void
    {
        $this->seedSource();
        $batch = $this->runPipeline();
        $counts = $this->targetCounts();

        $this->app->detectEnvironment(fn () => 'production');
        $this->assertTrue(app()->environment('production'));

        foreach ([
            ['legacy:stage', ['--snapshot-sha256' => self::SHA]],
            ['legacy:map-types', []],
            ['legacy:apply', ['--actor' => 'x@example.test']],
            ['legacy:suggest-pairs', []],
            ['legacy:reconcile', []],
            ['legacy:rollback', ['batch' => $batch->uuid]],
            ['legacy:report', []],
        ] as [$command, $args]) {
            $this->artisan($command, $args)->expectsOutputToContain('refuses to run when APP_ENV=production')->assertFailed();
        }

        $this->assertSame($counts, $this->targetCounts(), 'nothing changed');
        $this->assertSame('applied', $batch->fresh()->status);
    }

    public function test_the_audit_connection_must_be_configured_and_never_be_the_application_database(): void
    {
        config(['database.connections.legacy_audit.database' => '']);
        $this->artisan('legacy:stage', ['--snapshot-sha256' => self::SHA])->expectsOutputToContain('is not configured')->assertFailed();

        // Config-only check (no connection is opened). Restore the real settings afterwards so the
        // suite works on both the SQLite and the MariaDB configuration.
        $original = ['default' => config('database.default'), 'probe' => config('database.connections.guard_probe')];
        config([
            'database.connections.guard_probe' => ['driver' => 'mysql', 'host' => 'h', 'port' => '1', 'database' => 'same_db'],
            'database.connections.legacy_audit' => ['driver' => 'mysql', 'host' => 'h', 'port' => '1', 'database' => 'same_db'],
            'database.default' => 'guard_probe',
        ]);
        try {
            LegacyGuard::assertSourceConnectionSafe();
            $this->fail('the legacy connection must not be allowed to equal the application database');
        } catch (LegacyException $e) {
            $this->assertStringContainsString('points at the application database', $e->getMessage());
        } finally {
            config(['database.default' => $original['default'], 'database.connections.guard_probe' => $original['probe']]);
        }
    }

    public function test_the_whole_pipeline_never_modifies_the_legacy_source(): void
    {
        $this->seedSource();
        $this->en(['id' => 500, 'map_url' => 'https://m.test/?q=30.0,31.0', 'phone' => '01111111111']);
        $this->ar(['id' => 500, 'map_url' => 'https://m.test/?q=30.0,31.0', 'phone' => '01111111111']);
        $before = $this->sourceSnapshot();

        $batch = $this->runPipeline();
        $this->artisan('legacy:suggest-pairs')->assertSuccessful();
        $this->artisan('legacy:reconcile', ['--report' => true])->assertSuccessful();
        $this->artisan('legacy:rollback', ['batch' => $batch->uuid])->assertSuccessful();

        $this->assertSame($before, $this->sourceSnapshot());
    }
}
