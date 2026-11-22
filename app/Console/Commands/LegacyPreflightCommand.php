<?php

namespace App\Console\Commands;

use App\Services\Legacy\LegacyGuard;
use App\Services\Legacy\LegacySource;
use Illuminate\Support\Facades\DB;

/**
 * Read-only pre-flight: runs every migration guard against the REAL configured connections and
 * prints only non-secret facts (environment, resolved host, port, database names, account name,
 * grant verdict, source row counts). Writes nothing anywhere.
 */
class LegacyPreflightCommand extends LegacyCommand
{
    protected $signature = 'legacy:preflight
        {--snapshot-sha256= : SHA-256 of the frozen SQL snapshot (must equal the recorded value)}';

    protected $description = 'Verify the migration is local-only, the destination is allow-listed and the source is a SELECT-only loopback connection. Writes nothing.';

    public function handle(LegacySource $source): int
    {
        return $this->guarded(function () use ($source) {
            LegacyGuard::assertRunnable((string) $this->option('snapshot-sha256'));

            $srcName = (string) config('legacy.connection');
            $src = (array) config('database.connections.'.$srcName);
            $dst = (array) config('database.connections.'.config('database.default'));
            $live = (array) DB::connection($srcName)->selectOne('SELECT DATABASE() AS db, @@port AS port, CURRENT_USER() AS account');
            $dest = (array) DB::connection()->selectOne('SELECT DATABASE() AS db');

            $this->table(['Check', 'Value'], [
                ['environment', app()->environment()],
                ['snapshot sha256', 'matches recorded value'],
                ['source host (resolved)', gethostbyname((string) ($src['host'] ?? ''))],
                ['source port / database (live)', $live['port'].' / '.$live['db']],
                ['source account', (string) $live['account']],
                ['source grants', 'SELECT-only (verified with SHOW GRANTS)'],
                ['destination host (resolved)', gethostbyname((string) ($dst['host'] ?? ''))],
                ['destination database (live)', (string) $dest['db']],
            ]);

            $rows = [];
            foreach (LegacySource::TABLES as $table) {
                $rows[] = [$table, $source->count($table)];
            }
            $this->table(['Source table', 'Rows'], $rows);
            $this->info('Preflight passed. Nothing was written.');

            return self::SUCCESS;
        });
    }
}
