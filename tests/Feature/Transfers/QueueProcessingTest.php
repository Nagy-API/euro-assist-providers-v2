<?php

namespace Tests\Feature\Transfers;

use App\Models\ExportJob;
use App\Models\Provider;
use App\Services\OpsHealth;
use App\Services\Transfers\ExportService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A queued export is NOT a completed export. These tests use the real database queue (not sync) and the
 * real `queue:work` command, exactly what the scheduled task runs on the server.
 */
class QueueProcessingTest extends TransfersTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        Provider::factory()->bilingual()->create(['provider_type_id' => $this->type->id]);
    }

    public function test_an_export_stays_queued_until_a_worker_runs_then_completes_with_a_file(): void
    {
        $job = app(ExportService::class)->dispatch($this->editor(), ExportJob::SCOPE_ALL, 'en');

        $this->assertSame(ExportJob::STATUS_QUEUED, $job->status, 'no worker has run: still queued, not completed');
        $this->assertSame(1, DB::table('jobs')->count());

        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--max-time' => 30, '--tries' => 3]));

        $job->refresh();
        $this->assertSame(ExportJob::STATUS_COMPLETED, $job->status);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertNotEmpty($job->stored_path ?? $job->path ?? 'x');
        $this->assertNotEmpty(Storage::disk('local')->allFiles(), 'a workbook was written to private storage');
    }

    public function test_the_scheduler_runs_a_bounded_worker_every_minute_with_an_expiring_overlap_lock(): void
    {
        $events = collect(app(Schedule::class)->events());

        $worker = $events->first(fn ($e) => str_contains((string) $e->command, 'queue:work'));
        $this->assertNotNull($worker);
        $this->assertSame('* * * * *', $worker->expression);
        $this->assertStringContainsString('--stop-when-empty', $worker->command);
        $this->assertStringContainsString('--max-time=55', $worker->command);
        $this->assertTrue($worker->withoutOverlapping);
        $this->assertLessThanOrEqual(10, $worker->expiresAt, 'a stale overlap lock must expire in minutes, not the 24 h default');

        $this->assertNotNull($events->first(fn ($e) => $e->description === 'ops-scheduler-heartbeat'));
        $this->assertGreaterThan(3600, (int) config('queue.connections.database.retry_after'), 'retry_after must exceed the longest job timeout');
    }

    public function test_ops_check_reports_a_missing_scheduler_and_a_stuck_queue(): void
    {
        $job = app(ExportService::class)->dispatch($this->editor(), ExportJob::SCOPE_ALL, 'en');
        DB::table('jobs')->update(['created_at' => now()->subMinutes(20)->timestamp, 'available_at' => now()->subMinutes(20)->timestamp]);
        ExportJob::query()->whereKey($job->id)->update(['created_at' => now()->subMinutes(20)]);

        $byName = collect((new OpsHealth)->run())->keyBy('name');
        $this->assertSame(OpsHealth::FAIL, $byName['scheduler heartbeat']['status']);
        $this->assertSame(OpsHealth::FAIL, $byName['pending jobs']['status']);
        $this->assertSame(OpsHealth::FAIL, $byName['exports queued > 5 min']['status']);

        Cache::forever(OpsHealth::SCHEDULER_KEY, now()->toIso8601String());
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--max-time' => 30]);

        $byName = collect((new OpsHealth)->run())->keyBy('name');
        $this->assertSame(OpsHealth::OK, $byName['scheduler heartbeat']['status']);
        $this->assertSame(OpsHealth::OK, $byName['pending jobs']['status']);
        $this->assertSame(OpsHealth::OK, $byName['exports queued > 5 min']['status']);
    }

    public function test_ops_check_flags_debug_production_mismatch_roles_and_exposed_review_route_without_secrets(): void
    {
        DB::table('users')->insert(['name' => 'L', 'email' => 'l@example.test', 'password' => 'x', 'role' => 'super_admin', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->insert(['name' => 'O', 'email' => 'o@example.test', 'password' => 'x', 'role' => 'root', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        config(['app.debug' => true]);

        $checks = collect((new OpsHealth)->run())->keyBy('name');
        $this->assertSame(OpsHealth::FAIL, $checks['APP_DEBUG']['status']);
        $this->assertSame(OpsHealth::WARN, $checks['legacy super_admin']['status']);
        $this->assertSame(OpsHealth::FAIL, $checks['unknown roles']['status']);

        $text = json_encode($checks->all());
        foreach (['password', 'APP_KEY', 'DB_PASSWORD', 'secret'] as $needle) {
            $this->assertStringNotContainsString($needle.'=', $text);
        }
        $this->assertStringNotContainsString(config('app.key'), $text);
    }

    public function test_the_release_manifest_detects_changed_and_extra_files(): void
    {
        $path = base_path('release-manifest.json');
        $files = OpsHealth::fingerprint();
        try {
            file_put_contents($path, json_encode(['version' => 'test', 'files' => $files]));
            $this->assertSame(OpsHealth::OK, collect((new OpsHealth)->run())->firstWhere('group', 'code')['status']);

            $tampered = $files;
            $first = array_key_first($tampered);
            $tampered[$first] = str_repeat('0', 64);
            $tampered['app/Imaginary.php'] = str_repeat('1', 64);
            file_put_contents($path, json_encode(['version' => 'test', 'files' => $tampered]));
            $code = collect((new OpsHealth)->run())->firstWhere('group', 'code');
            $this->assertSame(OpsHealth::FAIL, $code['status']);
            $this->assertStringContainsString($first, $code['detail']);
        } finally {
            @unlink($path);
        }
    }
}
