<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Read-only system status. Shows only non-secret operational facts; it never prints environment
 * values wholesale, hosts, usernames, passwords, keys or tokens.
 */
class SystemStatusController extends Controller
{
    public function __invoke(): View
    {
        Gate::authorize('settings.manage');

        $driver = DB::connection()->getDriverName();

        $rows = [
            'application' => [
                'app_name' => (string) config('app.name'),
                'environment' => (string) config('app.env'),
                'debug' => config('app.debug') ? 'on' : 'off',
                'timezone' => (string) config('app.timezone'),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
            ],
            'drivers' => [
                'database' => $driver,
                'queue' => (string) config('queue.default'),
                'cache' => (string) config('cache.default'),
                'session' => (string) config('session.driver'),
                'mail' => (string) config('mail.default'),
                'featured_disk' => (string) config('providers.featured.disk'),
            ],
            'imports' => [
                'max_file_bytes' => (int) config('providers.imports.max_file_bytes'),
                'max_rows' => (int) config('providers.imports.max_rows'),
                'chunk_size' => (int) config('providers.imports.chunk_size'),
                'retention_hours' => (int) config('providers.imports.retention_hours'),
            ],
            'exports' => [
                'max_rows' => (int) config('providers.exports.max_rows'),
                'chunk_size' => (int) config('providers.exports.chunk_size'),
                'retention_hours' => (int) config('providers.exports.retention_hours'),
            ],
            'localization' => [
                'supported_locales' => implode(', ', config('providers.locales')),
                'default_locale' => (string) config('providers.default_locale'),
                'rtl_locales' => implode(', ', config('providers.rtl_locales')),
            ],
            'pagination' => [
                'default' => (int) config('providers.pagination.default'),
                'max' => (int) config('providers.pagination.max'),
            ],
        ];

        return view('settings.status', ['groups' => $rows, 'warnings' => $this->warnings()]);
    }

    /** @return list<string> translation keys under ui.settings.warn_* */
    private function warnings(): array
    {
        $warnings = [];
        if (config('app.debug') && app()->environment('production')) {
            $warnings[] = 'debug_in_production';
        } elseif (config('app.debug')) {
            $warnings[] = 'debug_on';
        }
        if (config('queue.default') === 'sync') {
            $warnings[] = 'queue_sync';
        }

        return $warnings;
    }
}
