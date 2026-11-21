<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
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
        $this->defineGates();
        $this->configureRateLimiters();
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
        if ($this->app->environment('production') && config('app.debug')) {
            throw new RuntimeException('APP_DEBUG must be false when APP_ENV=production.');
        }
    }

    private function configureRateLimiters(): void
    {
        // Keyed by e-mail + IP so one attacker cannot lock out an account from everywhere
        // and one IP cannot spray many accounts.
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        RateLimiter::for('api-v2', function (Request $request) {
            return Limit::perMinute((int) config('providers.api.rate_limit_per_minute', 60))
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip());
        });
    }
}
