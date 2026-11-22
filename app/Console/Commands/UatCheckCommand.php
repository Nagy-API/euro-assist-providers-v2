<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fail-closed check used by start-uat.ps1 / reset-uat.ps1 before anything runs against the owner
 * UAT database. It verifies the EFFECTIVE configuration of this process (not the .env file):
 *
 * - environment is `local` (never production/staging), and configuration is not cached
 *   (a cached config would silently ignore the launcher's database overrides);
 * - the default connection is MySQL/MariaDB on a loopback host, without a database URL;
 * - the configured database AND the database the server actually answers for are exactly the
 *   UAT database (uat.database), which is never one of the protected databases.
 *
 * Prints only non-secret facts and writes nothing.
 */
class UatCheckCommand extends Command
{
    protected $signature = 'uat:check';

    protected $description = 'Verify this process targets ONLY the isolated local UAT database (writes nothing).';

    public function handle(): int
    {
        try {
            $facts = $this->verify();
        } catch (Throwable $e) {
            $this->error('UAT check failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Check', 'Value'], $facts);
        $this->info('UAT target verified.');

        return self::SUCCESS;
    }

    /** @return list<array{0: string, 1: string}> */
    private function verify(): array
    {
        $expected = (string) config('uat.database');
        $protected = (array) config('uat.protected_databases');

        if ($expected === '' || in_array($expected, $protected, true)) {
            throw new \RuntimeException('uat.database is empty or names a protected database.');
        }
        if (! $this->laravel->environment('local')) {
            throw new \RuntimeException('APP_ENV must be local for UAT (got '.$this->laravel->environment().').');
        }
        if ($this->laravel->configurationIsCached()) {
            throw new \RuntimeException('configuration is cached; run "php artisan config:clear" first.');
        }

        $name = (string) config('database.default');
        $conn = (array) config('database.connections.'.$name);
        if (! in_array($conn['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException('the default connection is not MySQL/MariaDB.');
        }
        if (filled($conn['url'] ?? null)) {
            throw new \RuntimeException('a database URL is set; only explicit local settings are accepted.');
        }

        $host = (string) ($conn['host'] ?? '');
        $resolved = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : gethostbyname($host);
        if (! in_array($resolved, ['127.0.0.1', '::1'], true)) {
            throw new \RuntimeException('the database host does not resolve to a loopback address.');
        }

        $configured = (string) ($conn['database'] ?? '');
        if ($configured !== $expected) {
            throw new \RuntimeException("the configured database is '{$configured}', expected '{$expected}'.");
        }

        $live = (array) DB::connection($name)->selectOne('SELECT DATABASE() AS db, @@port AS port');
        if ((string) ($live['db'] ?? '') !== $expected) {
            throw new \RuntimeException('the server answered for a different database.');
        }
        if ((string) ($live['port'] ?? '') !== (string) ($conn['port'] ?? '')) {
            throw new \RuntimeException('the server answered on an unexpected port.');
        }

        return [
            ['environment', (string) $this->laravel->environment()],
            ['database host (resolved)', $resolved],
            ['port (live)', (string) $live['port']],
            ['database (live)', (string) $live['db']],
        ];
    }
}
