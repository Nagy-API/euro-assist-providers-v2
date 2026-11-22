<?php

namespace App\Services\Legacy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Fail-closed pre-flight refusals shared by every legacy command, service and review screen
 * (docs/08_SECURITY.md "Legacy migration safeguards", ADR-014).
 *
 * - LOCAL-ONLY: the pipeline (staging, mapping, suggestions, apply, reconcile, report, rollback and
 *   pair review) runs only in the environments listed in legacy.allowed_environments (default
 *   local + testing). Staging/production are refused; production migration is NOT authorized.
 * - Destination: the application (default) connection must be a loopback MySQL/MariaDB database on
 *   the explicit allow-list legacy.allowed_destination_databases (or SQLite in the testing env).
 * - Source: the legacy_audit connection must be a loopback host, the expected database name,
 *   never the destination, and its account must hold SELECT only (verified with SHOW GRANTS on
 *   the live connection before any read).
 */
final class LegacyGuard
{
    private const READ_ONLY_PRIVILEGES = ['SELECT', 'SHOW VIEW', 'USAGE'];

    /** Kept for existing call sites: now means "local-only environment AND safe destination". */
    public static function assertNotProduction(): void
    {
        self::assertLocalOnly();
        self::assertDestinationSafe();
    }

    public static function assertLocalOnly(): void
    {
        $allowed = (array) config('legacy.allowed_environments', ['local', 'testing']);
        if (! in_array(app()->environment(), $allowed, true) || app()->environment('production')) {
            throw new LegacyException('The legacy migration pipeline is LOCAL-ONLY; refusing to run in APP_ENV='.app()->environment().'.');
        }
    }

    /** The provided checksum must equal the recorded one for the frozen snapshot. */
    public static function assertSnapshot(string $given): void
    {
        $recorded = (string) config('legacy.snapshot_sha256');

        if ($given === '' || $recorded === '' || ! hash_equals(strtolower($recorded), strtolower(trim($given)))) {
            throw new LegacyException('The snapshot SHA-256 does not match the recorded value; refusing to continue.');
        }
    }

    /** The destination (application) database must be an allow-listed local database. */
    public static function assertDestinationSafe(): void
    {
        $name = (string) config('database.default');
        $dest = config('database.connections.'.$name);
        if (! is_array($dest)) {
            throw new LegacyException('The destination connection is not configured; refusing.');
        }
        if (filled($dest['url'] ?? null)) {
            throw new LegacyException('The destination connection uses a database URL; only explicit local settings are accepted.');
        }

        $driver = (string) ($dest['driver'] ?? '');
        if ($driver === 'sqlite') {
            if (! app()->environment('testing')) {
                throw new LegacyException('SQLite is only accepted as a migration destination in the testing environment.');
            }

            return;
        }
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new LegacyException("Unsupported destination driver '{$driver}'.");
        }

        self::assertLoopback((string) ($dest['host'] ?? ''), 'destination');
        $allowed = array_values(array_filter(array_map('trim', (array) config('legacy.allowed_destination_databases', []))));
        if (! in_array((string) ($dest['database'] ?? ''), $allowed, true)) {
            throw new LegacyException('The destination database is not on the local migration allow-list (legacy.allowed_destination_databases); refusing.');
        }
    }

    /**
     * The audit connection must exist, be local, be the expected frozen database, never be the
     * application database, and (verified live) hold no privilege beyond SELECT.
     */
    public static function assertSourceConnectionSafe(): void
    {
        $name = (string) config('legacy.connection');
        $source = config('database.connections.'.$name);
        if (! is_array($source) || ! isset($source['database']) || $source['database'] === '') {
            throw new LegacyException("The read-only '{$name}' connection is not configured (set the LEGACY_DB_* environment variables).");
        }
        if (filled($source['url'] ?? null)) {
            throw new LegacyException('The legacy connection uses a database URL; only explicit local settings are accepted.');
        }

        $driver = (string) ($source['driver'] ?? '');
        $default = config('database.connections.'.config('database.default'));

        if ($driver === 'sqlite') {
            if (! app()->environment('testing')) {
                throw new LegacyException('A SQLite legacy source is only accepted in the testing environment.');
            }
        } else {
            self::assertLoopback((string) ($source['host'] ?? ''), 'legacy source');
            $expected = (string) config('legacy.expected_source_database', '');
            if ($expected === '' || $source['database'] !== $expected) {
                throw new LegacyException('The legacy connection does not point at the expected frozen audit database (legacy.expected_source_database); refusing.');
            }
        }

        if (is_array($default)
            && ($default['driver'] ?? null) === $driver
            && ($default['database'] ?? null) === $source['database']
            && self::resolve((string) ($default['host'] ?? '')) === self::resolve((string) ($source['host'] ?? ''))
            && (string) ($default['port'] ?? '') === (string) ($source['port'] ?? '')
            && $driver !== 'sqlite') {
            throw new LegacyException('The legacy audit connection points at the application database; refusing.');
        }

        if ($driver !== 'sqlite') {
            self::assertSourceReadOnly(DB::connection($name), $source);
        }
    }

    /**
     * Live verification on the real connection: the server answers for the configured database
     * and port, and the account's grants contain only read privileges.
     *
     * @param  array<string, mixed>  $config
     */
    public static function assertSourceReadOnly(ConnectionInterface $connection, array $config): void
    {
        $row = (array) $connection->selectOne('SELECT DATABASE() AS db, @@port AS port');
        if ((string) ($row['db'] ?? '') !== (string) $config['database']
            || (string) ($row['port'] ?? '') !== (string) ($config['port'] ?? '')) {
            throw new LegacyException('The legacy connection answered for an unexpected database or port; refusing.');
        }

        foreach ($connection->select('SHOW GRANTS FOR CURRENT_USER()') as $grant) {
            $line = (string) array_values((array) $grant)[0];
            self::assertReadOnlyGrant($line);
        }
    }

    /** Public for unit tests: one SHOW GRANTS line must grant read privileges only. */
    public static function assertReadOnlyGrant(string $line): void
    {
        if (! preg_match('/^GRANT\s+(.+?)\s+ON\s+/i', $line, $m)) {
            throw new LegacyException('Unrecognised grant on the legacy account; refusing.');
        }
        if (stripos($line, 'WITH GRANT OPTION') !== false) {
            throw new LegacyException('The legacy account may grant privileges; a SELECT-only account is required.');
        }
        foreach (array_map('trim', explode(',', strtoupper($m[1]))) as $privilege) {
            if (! in_array($privilege, self::READ_ONLY_PRIVILEGES, true)) {
                throw new LegacyException("The legacy account holds the non-read privilege {$privilege}; a SELECT-only account is required.");
            }
        }
    }

    public static function assertRunnable(?string $snapshot = null): void
    {
        self::assertNotProduction();
        self::assertSourceConnectionSafe();
        if ($snapshot !== null) {
            self::assertSnapshot($snapshot);
        }
    }

    private static function assertLoopback(string $host, string $what): void
    {
        $resolved = self::resolve($host);
        if (! in_array($resolved, ['127.0.0.1', '::1'], true)) {
            throw new LegacyException("The {$what} host does not resolve to a loopback address; the migration is local-only.");
        }
    }

    private static function resolve(string $host): string
    {
        $host = trim($host, '[] ');
        if ($host === '') {
            return '';
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host === '0:0:0:0:0:0:0:1' ? '::1' : $host;
        }
        $ip = gethostbyname($host);

        return $ip === $host ? '' : $ip;
    }
}
