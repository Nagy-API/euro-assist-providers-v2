<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->refuseUnsafeProductionConfiguration();
        $this->configureTrustedProxies();
        $this->defineGates();
        $this->configureRateLimiters();

        // Livewire update requests bypass route middleware unless it is persistent: make sure a
        // deactivated account loses access to Livewire actions too.
        Livewire::addPersistentMiddleware([EnsureUserIsActive::class]);
    }

    /**
     * One gate per approved permission key. No Gate::before / admin bypass: Admin simply holds
     * every permission, so the matrix stays the single source of truth. Inactive users hold none.
     */
    private function defineGates(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user) => $user->hasPermission($permission));
        }
    }

    /**
     * Debug mode in production leaks stack traces, environment values and queries.
     */
    private function refuseUnsafeProductionConfiguration(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        if (config('app.debug')) {
            throw new RuntimeException('APP_DEBUG must be false when APP_ENV=production.');
        }
        if (! str_starts_with((string) config('app.url'), 'https://')) {
            throw new RuntimeException('APP_URL must be an https:// URL when APP_ENV=production.');
        }
        if (config('session.secure') !== true) {
            throw new RuntimeException('SESSION_SECURE_COOKIE must be true when APP_ENV=production.');
        }
        if (trim((string) config('app.trusted_proxies')) === '*') {
            throw new RuntimeException('TRUSTED_PROXIES must list the reverse-proxy addresses (not "*") when APP_ENV=production.');
        }

        // Generated links (password reset/invitation mails) must never be built from the
        // client-controlled Host header: pin them to the configured canonical URL.
        URL::forceRootUrl((string) config('app.url'));
        URL::forceScheme('https');
    }

    /**
     * Behind Plesk/nginx the real client IP arrives via X-Forwarded-For. Only the configured proxy
     * addresses are trusted; with none configured, forwarded headers are ignored.
     */
    private function configureTrustedProxies(): void
    {
        $proxies = trim((string) config('app.trusted_proxies'));
        if ($proxies === '') {
            return;
        }

        TrustProxies::at($proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
    }

    private function configureRateLimiters(): void
    {
        // Keyed by e-mail + IP so one attacker cannot lock out an account from everywhere
        // and one IP cannot spray many accounts.
        RateLimiter::for('login', function (Request $request) {
            // Malformed input (arrays/objects) must never raise an error here: only a scalar string is used.
            $raw = $request->input('email');
            $email = is_string($raw) ? mb_strtolower(trim($raw)) : '';

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
                // Distributed guessing against one account from many addresses.
                Limit::perHour(30)->by('account:'.$email),
            ];
        });

        RateLimiter::for('api-v2', function (Request $request) {
            return Limit::perMinute((int) config('providers.api.rate_limit_per_minute', 60))
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip());
        });
    }
}
