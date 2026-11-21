<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Applies the visitor's chosen UI locale (session), falling back to the configured default. */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('providers.locales');
        $locale = $request->hasSession() ? $request->session()->get('locale') : null;

        if (! in_array($locale, $supported, true)) {
            $locale = config('providers.default_locale');
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
