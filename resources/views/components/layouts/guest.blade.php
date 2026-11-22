<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), config('providers.rtl_locales')) ? 'rtl' : 'ltr' }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ isset($title) && trim((string) $title) !== '' ? trim((string) $title).' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-slate-50 text-slate-900 antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:start-3 focus:top-3 focus:z-50 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-teal-800 focus:shadow-lg">{{ __('ui.common.skip_to_content') }}</a>
    <main id="main" tabindex="-1" class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-4 py-10 focus:outline-none">
        <div class="mb-6 text-center">
            <span class="mx-auto mb-3 inline-flex size-12 items-center justify-center rounded-xl bg-teal-700 text-white" aria-hidden="true"><x-ui.icon name="building" class="size-6" /></span>
            <h1 class="text-xl font-semibold">{{ config('app.name') }}</h1>
            @if (isset($title) && trim((string) $title) !== '')
                <p class="mt-1 text-sm text-slate-600">{{ $title }}</p>
            @endif
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            {{ $slot }}
        </div>
        <form method="POST" action="{{ route('locale.update', app()->getLocale() === 'ar' ? 'en' : 'ar') }}" class="mt-4 text-center">
            @csrf
            <button type="submit" class="inline-flex min-h-10 items-center gap-2 rounded-md px-3 text-sm font-medium text-teal-800 underline underline-offset-2 hover:bg-slate-100" lang="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
                <x-ui.icon name="globe" class="size-4" />{{ app()->getLocale() === 'ar' ? 'English' : 'العربية' }}
            </button>
        </form>
    </main>
</body>
</html>
