<?php

namespace Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies migrations (forward and backward) and the database queue/cache against the real
 * test database. DatabaseMigrations starts from migrate:fresh, so these tests are independent
 * of earlier tests and leave behind a fully migrated, empty schema.
 */
class InfrastructureTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        // Leave no queue/cache residue for later tests, whatever happened above.
        if (Schema::hasTable('jobs')) {
            DB::table('jobs')->delete();
            DB::table('failed_jobs')->delete();
            DB::table('cache')->delete();
        }

        parent::tearDown();
    }

    public function test_all_migrations_can_rollback_and_reapply_without_seeded_accounts(): void
    {
        $this->seed();
        $this->assertDatabaseCount('users', 0);

        $this->artisan('migrate:rollback', ['--step' => 1000])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasTable('jobs'));
        $this->assertFalse(Schema::hasTable('cache'));
        $this->assertFalse(Schema::hasTable('providers'));
        $this->assertFalse(Schema::hasTable('provider_translations'));

        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasTable('sessions'));
        $this->assertTrue(Schema::hasTable('job_batches'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
        $this->assertTrue(Schema::hasTable('providers'));
    }

    public function test_synthetic_job_can_be_persisted_and_processed_using_only_database_services(): void
    {
        Queue::connection('database')->push(new FoundationQueueProbe);
        $this->assertDatabaseCount('jobs', 1);

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--tries' => 1,
        ])->assertSuccessful();

        $this->assertSame('processed', Cache::store('database')->get('foundation-probe'));
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame(0, DB::table('users')->count());
    }
}

class FoundationQueueProbe implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::store('database')->put('foundation-probe', 'processed', 60);
    }
}
