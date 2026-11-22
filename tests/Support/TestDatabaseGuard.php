<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Fails closed unless the configured database is an explicitly allow-listed, disposable
 * test target. It is evaluated by TestCase::refreshApplication(), i.e. BEFORE any
 * RefreshDatabase / DatabaseMigrations trait can run a destructive migration.
 */
final class TestDatabaseGuard
{
    public const MYSQL_DATABASE = 'euro_assist_v2_test';

    /** @var list<string> Accounts that exist solely for disposable test databases. */
    public const MYSQL_USERNAMES = ['v2_test', 'ci_test'];

    /** @var list<int> Loopback ports: the portable local MariaDB and a stock service container. */
    public const MYSQL_PORTS = [33316, 3306];

    /**
     * @param  array<string, mixed>  $connection  The resolved connection config.
     *
     * @throws RuntimeException
     */
    public static function assertSafe(
        string $environment,
        bool $configurationCached,
        string $driver,
        array $connection,
    ): void {
        $problems = [];

        if ($environment !== 'testing') {
            $problems[] = 'environment is not "testing"';
        }
        if ($configurationCached) {
            $problems[] = 'configuration is cached';
        }
        if (! empty($connection['url'])) {
            $problems[] = 'a database URL is configured';
        }

        if ($driver === 'sqlite') {
            if (($connection['database'] ?? null) !== ':memory:') {
                $problems[] = 'sqlite target is not :memory:';
            }
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            if (($connection['host'] ?? null) !== '127.0.0.1') {
                $problems[] = 'host is not 127.0.0.1';
            }
            if (! in_array((int) ($connection['port'] ?? 0), self::MYSQL_PORTS, true)) {
                $problems[] = 'port is not an allow-listed test port';
            }
            if (($connection['database'] ?? null) !== self::MYSQL_DATABASE) {
                $problems[] = 'database is not '.self::MYSQL_DATABASE;
            }
            if (! in_array($connection['username'] ?? null, self::MYSQL_USERNAMES, true)) {
                $problems[] = 'username is not an allow-listed test account';
            }
            if (! empty($connection['unix_socket'])) {
                $problems[] = 'unix_socket must not be used';
            }
        } else {
            $problems[] = 'driver "'.$driver.'" is not allow-listed';
        }

        if ($problems !== []) {
            throw new RuntimeException(
                'Refusing to run tests against an unsafe database target: '.implode('; ', $problems).'.'
            );
        }
    }
}
