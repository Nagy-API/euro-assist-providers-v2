<?php

namespace App\Services\Legacy;

/** Pre-flight refusals shared by every legacy command and service. */
final class LegacyGuard
{
    /** The pipeline never runs where real traffic is served. */
    public static function assertNotProduction(): void
    {
        if (app()->environment('production')) {
            throw new LegacyException('The legacy migration pipeline refuses to run when APP_ENV=production.');
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

    /**
     * The audit connection must exist and must never be the application's own database.
     */
    public static function assertSourceConnectionSafe(): void
    {
        $name = (string) config('legacy.connection');
        $source = config('database.connections.'.$name);
        if (! is_array($source) || ! isset($source['database']) || $source['database'] === '') {
            throw new LegacyException("The read-only '{$name}' connection is not configured (set the LEGACY_DB_* environment variables).");
        }

        $default = config('database.connections.'.config('database.default'));
        if (is_array($default)
            && ($default['driver'] ?? null) === ($source['driver'] ?? null)
            && ($default['database'] ?? null) === $source['database']
            && ($default['host'] ?? null) === ($source['host'] ?? null)
            && ($default['port'] ?? null) === ($source['port'] ?? null)
            && ($source['driver'] ?? null) !== 'sqlite') {
            throw new LegacyException('The legacy audit connection points at the application database; refusing.');
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
}
