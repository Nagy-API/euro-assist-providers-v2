<?php

use App\Http\Controllers\Api\Support\ApiErrorRenderer;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SetLocale::class]);
        $middleware->append(SecurityHeaders::class);

        // Trusted proxies come from config('app.trusted_proxies') and are applied in
        // AppServiceProvider::boot (config-cache safe; env() is null here once config is cached).

        // Outside local/testing the app only answers to the exact host of APP_URL (Host-header
        // poisoning guard). Evaluated per request from config, so it survives `config:cache`.
        $middleware->trustHosts(at: function (): array {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);

            return is_string($host) && $host !== '' ? ['^'.preg_quote($host).'$'] : [];
        }, subdomains: false);
        $middleware->alias(['active' => EnsureUserIsActive::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // /api/* errors always use the V2 error envelope and never expose internals.
        $exceptions->render(function (Throwable $e, Request $request) {
            return $request->is('api/*') ? app(ApiErrorRenderer::class)->render($e, $request) : null;
        });
    })->create();
