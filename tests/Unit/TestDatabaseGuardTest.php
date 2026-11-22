<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

class TestDatabaseGuardTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function mysql(array $overrides = []): array
    {
        return array_merge([
            'host' => '127.0.0.1', 'port' => 33316, 'database' => 'euro_assist_v2_test',
            'username' => 'v2_test', 'url' => '',
        ], $overrides);
    }

    public function test_allow_listed_targets_pass(): void
    {
        TestDatabaseGuard::assertSafe('testing', false, 'sqlite', ['database' => ':memory:', 'url' => '']);
        TestDatabaseGuard::assertSafe('testing', false, 'mysql', self::mysql());
        TestDatabaseGuard::assertSafe('testing', false, 'mariadb', self::mysql(['port' => '3306', 'username' => 'ci_test']));
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{0: string, 1: bool, 2: string, 3: array<string, mixed>}> */
    public static function unsafeTargets(): array
    {
        return [
            'wrong environment' => ['local', false, 'mysql', self::mysql()],
            'cached config' => ['testing', true, 'mysql', self::mysql()],
            'database url' => ['testing', false, 'mysql', self::mysql(['url' => 'mysql://x@y/z'])],
            'development database' => ['testing', false, 'mysql', self::mysql(['database' => 'euro_assist_v2_local'])],
            'legacy audit database' => ['testing', false, 'mysql', self::mysql(['database' => 'euro_assist_legacy_audit_20261121', 'username' => 'legacy_import'])],
            'suffix trick' => ['testing', false, 'mysql', self::mysql(['database' => 'euro_assist_v2_test_backup'])],
            'prefix trick' => ['testing', false, 'mysql', self::mysql(['database' => 'prod_euro_assist_v2_test'])],
            'remote host' => ['testing', false, 'mysql', self::mysql(['host' => 'db.example.com'])],
            'localhost name' => ['testing', false, 'mysql', self::mysql(['host' => 'localhost'])],
            'unknown port' => ['testing', false, 'mysql', self::mysql(['port' => 3307])],
            'dev user' => ['testing', false, 'mysql', self::mysql(['username' => 'v2_local'])],
            'root user' => ['testing', false, 'mysql', self::mysql(['username' => 'root'])],
            'null user' => ['testing', false, 'mysql', self::mysql(['username' => null])],
            'unix socket' => ['testing', false, 'mysql', self::mysql(['unix_socket' => '/tmp/x.sock'])],
            'file sqlite' => ['testing', false, 'sqlite', ['database' => 'database/database.sqlite', 'url' => '']],
            'unsupported driver' => ['testing', false, 'pgsql', self::mysql()],
        ];
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    #[DataProvider('unsafeTargets')]
    public function test_unsafe_targets_fail_closed(string $env, bool $cached, string $driver, array $connection): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to run tests');
        TestDatabaseGuard::assertSafe($env, $cached, $driver, $connection);
    }
}
