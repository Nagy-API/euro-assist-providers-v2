@php
    $locale = app()->getLocale();
    $isRtl = in_array($locale, config('providers.rtl_locales'), true);
    $otherLocale = $locale === 'ar' ? 'en' : 'ar';
    $plainTitle = isset($title) && trim((string) $title) !== '' ? trim((string) $title) : null;
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#d10000">
    <title>{{ $plainTitle !== null ? $plainTitle.' · ' : '' }}{{ config('app.name') }}</title>
    <x-layouts.favicon />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full bg-paper font-sans text-slate-900 antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:start-3 focus:top-3 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-brand-800 focus:shadow-lg">{{ __('ui.common.skip_to_content') }}</a>

    <div class="grid min-h-screen lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
        {{-- Brand panel (Cards System login composition): authentic logo on a white card over the hero gradient. --}}
        <section class="ea-hero flex flex-col justify-between gap-8 px-6 py-8 sm:px-10 lg:px-16 lg:py-14" aria-label="Euro Assist">
            <div class="relative z-10">
                <span class="ea-logo-card inline-block p-2">
                    <img src="{{ asset('brand/euro-assist-logo-light.png') }}" alt="Euro Assist" width="381" height="245" class="h-16 w-auto sm:h-24">
                </span>
            </div>
            <div class="relative z-10 hidden max-w-lg animate-enter sm:block">
                <p class="mb-4 flex items-center gap-3 text-xs font-extrabold tracking-[0.16em] text-white uppercase"><span class="h-px w-8 bg-white/80" aria-hidden="true"></span>{{ __('ui.brand.product') }}</p>
                <p class="text-4xl leading-[1.1] font-extrabold tracking-tight lg:text-5xl">{{ __('auth.hero.title') }}</p>
                <p class="mt-4 text-base leading-relaxed text-white lg:text-lg">{{ __('auth.hero.subtitle') }}</p>
                <ul class="mt-8 space-y-3 text-sm font-bold">
                    @foreach (['providers' => 'building', 'bilingual' => 'globe', 'excel' => 'upload'] as $key => $icon)
                        <li class="flex items-center gap-3">
                            <span class="inline-flex size-9 items-center justify-center rounded-lg border border-white/30 bg-white/10"><x-ui.icon :name="$icon" class="size-5" /></span>
                            {{ __('auth.hero.'.$key) }}
                        </li>
                    @endforeach
                </ul>
            </div>
            <p class="relative z-10 hidden text-xs font-semibold text-white lg:block">Euro Assist · {{ __('ui.brand.staff_only') }}</p>
        </section>

        <main id="main" tabindex="-1" class="flex flex-col items-center justify-center px-4 py-10 focus:outline-none sm:px-8">
            <div class="w-full max-w-md animate-pop">
                <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-lg sm:p-9">
                    @if ($plainTitle !== null)
                        <h1 class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">{{ $plainTitle }}</h1>
                    @endif
                    @isset($subtitle)
                        <p class="mt-1 text-sm text-slate-600">{{ $subtitle }}</p>
                    @endisset
                    <div class="mt-6">
                        {{ $slot }}
                    </div>
                    <p class="mt-6 flex items-center gap-2 text-xs text-slate-600"><x-ui.icon name="shield" class="size-4" />{{ __('auth.secure_session') }}</p>
                </div>
                <form method="POST" action="{{ route('locale.update', $otherLocale) }}" class="mt-4 text-center">
                    @csrf
                    <button type="submit" class="inline-flex min-h-10 items-center gap-2 rounded-lg px-3 text-sm font-semibold text-slate-700 transition-colors hover:bg-white hover:text-brand-800" lang="{{ $otherLocale }}">
                        <x-ui.icon name="globe" class="size-4" />{{ $otherLocale === 'ar' ? 'العربية' : 'English' }}
                    </button>
                </form>
            </div>
        </main>
    </div>
    {{-- Livewire 4 bundles and starts Alpine. Guest pages have no Livewire component, so without this
         Alpine never started and x-data controls (the password visibility toggle) were inert. --}}
    @livewireScripts
</body>
</html>
