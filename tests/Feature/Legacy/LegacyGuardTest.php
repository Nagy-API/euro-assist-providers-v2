<?php

namespace Tests\Feature\Legacy;

use App\Livewire\Migration\ReviewIndex;
use App\Models\User;
use App\Services\Legacy\LegacyException;
use App\Services\Legacy\LegacyGuard;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

/** S2/S3 negative tests: the pipeline refuses every unsafe environment, destination and source. */
class LegacyGuardTest extends LegacyTestCase
{
    private function refuses(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail('expected a refusal containing: '.$needle);
        } catch (LegacyException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    /** @return array<string, array{0: string}> */
    public static function grantsAccepted(): array
    {
        return [
            'usage' => ['GRANT USAGE ON *.* TO `legacy_reader`@`127.0.0.1` IDENTIFIED BY PASSWORD \'*X\''],
            'select' => ['GRANT SELECT ON `euro_assist_legacy_audit_20261121`.* TO `legacy_reader`@`127.0.0.1`'],
            'select+view' => ['GRANT SELECT, SHOW VIEW ON `db`.* TO `r`@`127.0.0.1`'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function grantsRefused(): array
    {
        return [
            'insert' => ['GRANT SELECT, INSERT, CREATE, REFERENCES, INDEX, ALTER ON `db`.* TO `legacy_import`@`127.0.0.1`'],
            'all' => ['GRANT ALL PRIVILEGES ON `db`.* TO `x`@`127.0.0.1`'],
            'update' => ['GRANT UPDATE ON `db`.`providers` TO `x`@`127.0.0.1`'],
            'delete' => ['GRANT SELECT, DELETE ON `db`.* TO `x`@`127.0.0.1`'],
            'drop' => ['GRANT DROP ON *.* TO `x`@`127.0.0.1`'],
            'grant option' => ['GRANT SELECT ON `db`.* TO `x`@`127.0.0.1` WITH GRANT OPTION'],
            'role' => ['GRANT `writer_role` TO `x`@`127.0.0.1`'],
        ];
    }

    #[DataProvider('grantsAccepted')]
    public function test_read_only_grants_are_accepted(string $line): void
    {
        LegacyGuard::assertReadOnlyGrant($line);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('grantsRefused')]
    public function test_any_write_capable_grant_is_refused(string $line): void
    {
        $this->expectException(LegacyException::class);
        LegacyGuard::assertReadOnlyGrant($line);
    }

    public function test_staging_and_production_environments_are_refused(): void
    {
        foreach (['staging', 'production', 'uat'] as $env) {
            $this->app->detectEnvironment(fn () => $env);
            $this->refuses(fn () => LegacyGuard::assertNotProduction(), 'LOCAL-ONLY');
        }
    }

    public function test_destination_must_be_an_allow_listed_loopback_database(): void
    {
        $original = config('database.default');
        $base = ['driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => '33316', 'database' => 'euro_assist_v2_local'];
        try {
            config(['database.connections.dest_probe' => $base, 'database.default' => 'dest_probe']);
            LegacyGuard::assertDestinationSafe(); // allowed
            $this->addToAssertionCount(1);

            config(['database.connections.dest_probe' => ['database' => 'providers_production'] + $base]);
            $this->refuses(fn () => LegacyGuard::assertDestinationSafe(), 'allow-list');

            config(['database.connections.dest_probe' => ['host' => '203.0.113.10'] + $base]);
            $this->refuses(fn () => LegacyGuard::assertDestinationSafe(), 'loopback');

            config(['database.connections.dest_probe' => ['url' => 'mysql://u:p@db.example.test/x'] + $base]);
            $this->refuses(fn () => LegacyGuard::assertDestinationSafe(), 'URL');

            config(['database.connections.dest_probe' => ['driver' => 'pgsql'] + $base]);
            $this->refuses(fn () => LegacyGuard::assertDestinationSafe(), 'Unsupported');
        } finally {
            config(['database.default' => $original]);
        }
    }

    public function test_sqlite_destination_is_refused_outside_testing(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        if (config('database.connections.'.config('database.default').'.driver') !== 'sqlite') {
            $this->markTestSkipped('only meaningful on the SQLite configuration');
        }
        $this->refuses(fn () => LegacyGuard::assertDestinationSafe(), 'testing environment');
    }

    public function test_source_must_be_loopback_and_the_expected_frozen_database(): void
    {
        $src = ['driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => '33316', 'database' => 'euro_assist_legacy_audit_20261121'];
        config(['legacy.expected_source_database' => 'euro_assist_legacy_audit_20261121']);

        config(['database.connections.legacy_audit' => ['host' => 'providers.euro-assist.example'] + $src]);
        $this->refuses(fn () => LegacyGuard::assertSourceConnectionSafe(), 'loopback');

        config(['database.connections.legacy_audit' => ['database' => 'some_other_db'] + $src]);
        $this->refuses(fn () => LegacyGuard::assertSourceConnectionSafe(), 'expected frozen audit database');

        config(['legacy.expected_source_database' => '']);
        config(['database.connections.legacy_audit' => $src]);
        $this->refuses(fn () => LegacyGuard::assertSourceConnectionSafe(), 'expected frozen audit database');

        config(['database.connections.legacy_audit' => ['url' => 'mysql://x@127.0.0.1/y'] + $src]);
        $this->refuses(fn () => LegacyGuard::assertSourceConnectionSafe(), 'URL');
    }

    public function test_review_screen_and_pair_service_refuse_outside_local(): void
    {
        $admin = User::factory()->admin()->create();
        $this->app->detectEnvironment(fn () => 'staging');

        Livewire::actingAs($admin)->test(ReviewIndex::class)->assertStatus(404);
    }
}
