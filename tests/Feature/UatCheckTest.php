<?php

namespace Tests\Feature;

use App\Services\Legacy\LegacyException;
use App\Services\Legacy\LegacyGuard;
use Tests\TestCase;

/** `php artisan uat:check` must refuse every target other than the isolated local UAT database. */
class UatCheckTest extends TestCase
{
    /** @param array<string, mixed> $override */
    private function probe(array $override): void
    {
        config([
            'database.connections.uat_probe' => $override + [
                'driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => '33316', 'database' => 'euro_assist_v2_uat',
            ],
            'database.default' => 'uat_probe',
        ]);
    }

    private function refuses(string $needle): void
    {
        $this->artisan('uat:check')->expectsOutputToContain($needle)->assertFailed();
    }

    public function test_the_uat_database_is_fixed_and_never_a_protected_database(): void
    {
        $this->assertSame('euro_assist_v2_uat', config('uat.database'));
        $this->assertNotContains(config('uat.database'), config('uat.protected_databases'));
        $this->assertContains('euro_assist_v2_local', config('uat.protected_databases'));
        $this->assertContains('euro_assist_legacy_audit_20261121', config('uat.protected_databases'));
    }

    public function test_it_refuses_outside_the_local_environment(): void
    {
        $this->probe([]);
        foreach (['production', 'staging', 'testing'] as $env) {
            $this->app->detectEnvironment(fn () => $env);
            $this->refuses('APP_ENV must be local');
        }
    }

    public function test_it_refuses_protected_wrong_or_remote_targets_before_connecting(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $this->probe(['database' => 'euro_assist_v2_local']);
        $this->refuses("configured database is 'euro_assist_v2_local'");

        $this->probe(['database' => 'euro_assist_legacy_audit_20261121']);
        $this->refuses('expected');

        $this->probe(['host' => '203.0.113.10']);
        $this->refuses('loopback');

        $this->probe(['url' => 'mysql://u:p@db.example.test/euro_assist_v2_uat']);
        $this->refuses('database URL');

        $this->probe(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->refuses('not MySQL/MariaDB');
    }

    public function test_a_protected_uat_name_is_refused_even_if_misconfigured(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['uat.database' => 'euro_assist_v2_local']);
        $this->probe(['database' => 'euro_assist_v2_local']);
        $this->refuses('protected database');
    }

    public function test_migration_review_may_write_to_the_uat_database_but_not_to_unlisted_ones(): void
    {
        $original = config('database.default');
        try {
            $this->probe([]);
            LegacyGuard::assertDestinationSafe();
            $this->addToAssertionCount(1);

            $this->probe(['database' => 'euro_assist_v2_uat_copy']);
            $this->expectException(LegacyException::class);
            LegacyGuard::assertDestinationSafe();
        } finally {
            config(['database.default' => $original]);
        }
    }
}
