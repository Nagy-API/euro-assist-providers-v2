<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\ExportJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only operational self-check (ADR-022). Answers, from the running application itself, the questions
 * that decide whether a deployment is what it should be: which code, which configuration, which database,
 * which role values, is the scheduler running, is the queue draining. It never prints a secret (no .env
 * values, passwords, keys, tokens, hosts or user names) and never writes anything except the scheduler
 * heartbeat that the scheduled task itself produces.
 */
class OpsHealth
{
    public const SCHEDULER_KEY = 'ops:scheduler-heartbeat';

    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** Directories whose files make up "the code" for the fingerprint. */
    private const CODE_DIRS = ['app', 'bootstrap/app.php', 'config', 'database/migrations', 'database/seeders/DatabaseSeeder.php', 'lang', 'resources', 'routes', 'public/index.php'];

    /** @var list<array{group: string, name: string, status: string, detail: string}> */
    private array $checks = [];

    /** @return list<array{group: string, name: string, status: string, detail: string}> */
    public function run(): array
    {
        $this->checks = [];
        $this->configuration();
        $this->database();
        $this->roles();
        $this->routes();
        $this->queue();
        $this->code();

        return $this->checks;
    }

    private function add(string $group, string $name, string $status, string $detail): void
    {
        $this->checks[] = compact('group', 'name', 'status', 'detail');
    }

    private function configuration(): void
    {
        $env = (string) config('app.env');
        $debug = (bool) config('app.debug');
        $this->add('configuration', 'APP_ENV', $env === 'production' ? self::OK : self::FAIL, $env.($env === 'production' ? '' : ' (production must be "production")'));
        $this->add('configuration', 'APP_DEBUG', $debug ? self::FAIL : self::OK, $debug ? 'ON: stack traces and configuration are exposed. Set APP_DEBUG=false and clear/rebuild the config cache.' : 'off');
        $url = (string) config('app.url');
        $this->add('configuration', 'APP_URL', str_starts_with($url, 'https://') ? self::OK : self::WARN, $url);
        $this->add('configuration', 'config cache', app()->configurationIsCached() ? self::OK : self::WARN,
            app()->configurationIsCached() ? 'cached (re-run config:cache after every .env change)' : 'not cached');
        $this->add('configuration', 'drivers', self::OK, sprintf('cache=%s session=%s queue=%s', config('cache.default'), config('session.driver'), config('queue.default')));
        $this->add('configuration', 'PHP', version_compare(PHP_VERSION, '8.3.0', '>=') ? self::OK : self::FAIL, PHP_VERSION.' ('.PHP_SAPI.')');
        $proxies = trim((string) config('app.trusted_proxies'));
        $this->add('configuration', 'trusted proxies', $proxies === '' ? self::WARN : self::OK,
            $proxies === '' ? 'empty: behind a reverse proxy every visitor shares one client IP (one shared API rate-limit bucket); set TRUSTED_PROXIES to the proxy addresses' : 'set');
        $this->add('configuration', 'legacy API', self::OK, config('providers.legacy_api.enabled') ? 'enabled' : 'disabled');
    }

    private function database(): void
    {
        try {
            $name = DB::connection()->getDatabaseName();
            $this->add('database', 'connection', self::OK, DB::connection()->getDriverName().' / '.$name);
        } catch (Throwable $e) {
            $this->add('database', 'connection', self::FAIL, 'cannot connect ('.class_basename($e).')');

            return;
        }
        $missing = array_values(array_filter(
            ['users', 'providers', 'provider_translations', 'cache', 'cache_locks', 'sessions', 'jobs', 'failed_jobs', 'migrations', 'audit_events'],
            fn (string $t) => ! Schema::hasTable($t),
        ));
        $this->add('database', 'required tables', $missing === [] ? self::OK : self::FAIL,
            $missing === [] ? 'all present' : 'MISSING: '.implode(', ', $missing).' (wrong or incomplete database? do not run migrations on an unknown database)');
        if (Schema::hasTable('providers')) {
            $this->add('database', 'providers', self::OK, sprintf('%d live / %d total', DB::table('providers')->whereNull('deleted_at')->count(), DB::table('providers')->count()));
        }
    }

    private function roles(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'role')) {
            return;
        }
        $counts = DB::table('users')->selectRaw('role, count(*) as n')->groupBy('role')->pluck('n', 'role')->all();
        $valid = array_map(fn (Role $r) => $r->value, Role::cases());
        $legacy = array_intersect_key($counts, array_flip(array_keys(Role::LEGACY_ALIASES)));
        $unknown = array_diff_key($counts, array_flip($valid), $legacy);

        $this->add('accounts', 'role values', $unknown === [] && $legacy === [] ? self::OK : self::WARN,
            $counts === [] ? 'no users' : implode(', ', array_map(fn ($r, $n) => "$r=$n", array_keys($counts), $counts)));
        if ($legacy !== []) {
            $this->add('accounts', 'legacy super_admin', self::WARN, 'stored; read as administrator. Run "php artisan migrate --force" to normalize to admin (no privilege change).');
        }
        if ($unknown !== []) {
            $this->add('accounts', 'unknown roles', self::FAIL, implode(', ', array_keys($unknown)).' hold NO permissions; an administrator must assign a real role.');
        }
        $admins = DB::table('users')->whereIn('role', Role::adminValues())->where('is_active', true)->count();
        $this->add('accounts', 'active administrators', $admins > 0 ? self::OK : self::FAIL, (string) $admins);
        if (Schema::hasColumn('users', 'must_change_password')) {
            $this->add('accounts', 'temporary passwords pending', self::OK, (string) DB::table('users')->where('must_change_password', true)->count());
        }
    }

    private function routes(): void
    {
        $review = Route::has('migration.review.index');
        $production = app()->environment('production');
        $this->add('routes', 'migration review page', $review && $production ? self::FAIL : self::OK,
            $review ? 'REGISTERED'.($production ? ' in production: the application is not running in the intended environment' : ' (local/testing only)') : 'not registered');
        $this->add('routes', 'password recovery/invitation routes', Route::has('password.request') || Route::has('password.reset') ? self::FAIL : self::OK,
            Route::has('password.request') || Route::has('password.reset') ? 'present: code is older than ADR-020' : 'absent');
        $this->add('routes', 'route cache', app()->routesAreCached() ? self::OK : self::WARN, app()->routesAreCached() ? 'cached (re-run route:cache after deploying)' : 'not cached');
    }

    private function queue(): void
    {
        if (! Schema::hasTable('jobs')) {
            return;
        }
        $beat = Cache::get(self::SCHEDULER_KEY);
        $age = $beat ? (int) now()->diffInSeconds($beat, true) : null;
        $this->add('queue', 'scheduler heartbeat', $age !== null && $age <= 180 ? self::OK : self::FAIL,
            $age === null ? 'NEVER seen: the Plesk scheduled task is not running "php artisan schedule:run" (or runs it with a different cache/database)' : "last run {$age}s ago".($age > 180 ? ' (stale: the scheduled task stopped)' : ''));

        $pending = DB::table('jobs')->count();
        $oldest = DB::table('jobs')->min('created_at');
        $oldestAge = $oldest ? (int) now()->diffInSeconds(Carbon::createFromTimestamp((int) $oldest), true) : 0;
        $this->add('queue', 'pending jobs', $pending === 0 || $oldestAge < 300 ? self::OK : self::FAIL,
            "$pending pending".($pending ? ", oldest {$oldestAge}s".($oldestAge >= 300 ? ' (NOT being processed: no worker is draining the queue)' : '') : ''));
        $this->add('queue', 'failed jobs', DB::table('failed_jobs')->count() === 0 ? self::OK : self::WARN, (string) DB::table('failed_jobs')->count());

        if (Schema::hasTable('export_jobs')) {
            $stuck = ExportJob::query()->where('status', ExportJob::STATUS_QUEUED)->where('created_at', '<', now()->subMinutes(5))->count();
            $this->add('queue', 'exports queued > 5 min', $stuck === 0 ? self::OK : self::FAIL, (string) $stuck);
        }
        $this->add('queue', 'retry_after', (int) config('queue.connections.database.retry_after') > 3600 ? self::OK : self::WARN,
            (string) config('queue.connections.database.retry_after').' s (must exceed the longest job, 3600 s)');
    }

    private function code(): void
    {
        $manifest = base_path('release-manifest.json');
        if (! is_file($manifest)) {
            $this->add('code', 'release manifest', self::WARN, 'release-manifest.json not found: deployed code cannot be compared with a release');

            return;
        }
        $expected = json_decode((string) file_get_contents($manifest), true);
        if (! is_array($expected) || ! isset($expected['files'])) {
            $this->add('code', 'release manifest', self::FAIL, 'unreadable');

            return;
        }
        $actual = self::fingerprint();
        $changed = array_keys(array_diff_assoc($expected['files'], $actual));
        $extra = array_keys(array_diff_key($actual, $expected['files']));
        $ok = $changed === [] && $extra === [];
        $this->add('code', 'release '.($expected['version'] ?? '?'), $ok ? self::OK : self::FAIL,
            $ok ? count($actual).' files identical to the release'
                : count($changed).' differ/missing, '.count($extra).' extra. First: '.implode(', ', array_slice([...$changed, ...$extra], 0, 8)));
    }

    /** @return array<string, string> relative path => sha256 of the file with LF line endings */
    public static function fingerprint(): array
    {
        $out = [];
        foreach (self::CODE_DIRS as $entry) {
            $path = base_path($entry);
            $files = is_file($path) ? [new \SplFileInfo($path)] : (is_dir($path)
                ? iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)), false) : []);
            foreach ($files as $file) {
                if (! $file->isFile() || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'.git')) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
                $out[$rel] = hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($file->getPathname())));
            }
        }
        ksort($out);

        return $out;
    }

    /** @param  list<array{status: string}>  $checks */
    public static function failed(array $checks): int
    {
        return count(array_filter($checks, fn (array $c) => $c['status'] === self::FAIL));
    }
}
