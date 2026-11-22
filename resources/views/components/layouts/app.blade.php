@php
    $locale = app()->getLocale();
    $isRtl = in_array($locale, config('providers.rtl_locales'), true);
    $user = auth()->user();
    $sections = $user ? app(\App\Services\NavigationMenu::class)->for($user) : [];
    $otherLocale = $locale === 'ar' ? 'en' : 'ar';
    $plainTitle = isset($title) && trim((string) $title) !== '' ? trim((string) $title) : null;
    $pageTitle = $plainTitle !== null ? $plainTitle.' · '.config('app.name') : config('app.name');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#d10000">
    <title>{{ $pageTitle }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full bg-paper font-sans text-slate-900 antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:start-3 focus:top-3 focus:z-[70] focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-brand-800 focus:shadow-lg">{{ __('ui.common.skip_to_content') }}</a>

    <div x-data="{ nav: false }" x-on:keydown.escape.window="if (nav) { nav = false; $refs.navToggle.focus() }" class="min-h-screen lg:flex">
        {{-- Mobile backdrop --}}
        <div x-show="nav" x-cloak x-transition.opacity.duration.200ms class="fixed inset-0 z-30 bg-slate-950/55 lg:hidden" x-on:click="nav = false" aria-hidden="true"></div>

        {{-- Sidebar: static on large screens, off-canvas on small ones. Ink navigation with the
             Euro Assist red glow and hero-gradient active item, as in the Cards System. --}}
        <aside id="sidebar" x-bind:class="nav && '!flex'" aria-label="{{ __('ui.nav.label') }}"
               class="ea-sidebar hidden fixed inset-y-0 start-0 z-40 w-72 max-w-[85vw] flex-col overflow-y-auto shadow-lg lg:sticky lg:top-0 lg:z-auto lg:flex lg:h-screen lg:w-[264px] lg:max-w-none lg:shrink-0 lg:shadow-none">
            <div class="flex shrink-0 items-start justify-between gap-2 px-4 pt-5 pb-3">
                <a href="{{ route('dashboard') }}" class="group inline-flex flex-col gap-2 rounded-xl focus-visible:outline-white">
                    <span class="ea-logo-card block p-1.5 transition-transform duration-200 ease-brand group-hover:-translate-y-0.5">
                        <img src="{{ asset('brand/euro-assist-logo-light.png') }}" alt="Euro Assist" width="381" height="245" class="h-14 w-auto">
                    </span>
                    <span class="text-xs font-bold tracking-[0.12em] text-slate-300 uppercase">{{ __('ui.brand.product') }}</span>
                </a>
                <button type="button" class="inline-flex size-10 items-center justify-center rounded-lg text-slate-300 hover:bg-white/10 hover:text-white lg:hidden" x-on:click="nav = false; $refs.navToggle.focus()" aria-label="{{ __('ui.common.close_menu') }}">
                    <x-ui.icon name="x" />
                </button>
            </div>
            <nav class="flex-1 px-3 pb-4">
                @foreach ($sections as $section)
                    <div>
                        <h2 class="ea-nav-label">{{ __('ui.nav.section_'.$section['key']) }}</h2>
                        <ul class="space-y-0.5">
                            @foreach ($section['items'] as $item)
                                <li>
                                    <a href="{{ $item['url'] }}" @if($item['current']) aria-current="page" @endif @if($loop->first && $loop->parent->first) x-ref="sidebarFirst" @endif
                                       class="ea-nav-link">
                                        <x-ui.icon :name="$item['icon']" class="size-5" />
                                        <span>{{ $item['label'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </nav>
            @if ($user)
                <div class="shrink-0 p-3">
                    <div class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 p-3">
                        <span class="ea-avatar size-9 text-sm ring-2 ring-white/25" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                        <div class="min-w-0 flex-1 leading-tight">
                            <p class="truncate text-sm font-bold text-white" dir="auto">{{ $user->name }}</p>
                            <p class="truncate text-xs text-slate-400">{{ $user->role->label() }}</p>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="inline-flex size-9 items-center justify-center rounded-lg border border-white/20 text-slate-300 transition-colors hover:bg-white/10 hover:text-white" title="{{ __('auth.sign_out') }}">
                                <x-ui.icon name="logout" class="size-4" flip />
                                <span class="sr-only">{{ __('auth.sign_out') }}</span>
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-paper/90 px-4 backdrop-blur-sm sm:px-6">
                <button type="button" x-ref="navToggle" class="inline-flex size-10 items-center justify-center rounded-lg text-slate-700 hover:bg-white lg:hidden"
                        x-on:click="nav = !nav; if (nav) $nextTick(() => $refs.sidebarFirst?.focus())" x-bind:aria-expanded="nav.toString()" aria-controls="sidebar" aria-label="{{ __('ui.common.open_menu') }}">
                    <x-ui.icon name="menu" />
                </button>
                <a href="{{ route('dashboard') }}" class="shrink-0 lg:hidden" aria-label="{{ config('app.name') }}">
                    <img src="{{ asset('brand/euro-assist-logo-red.png') }}" alt="" width="367" height="155" class="h-8 w-auto rounded-md">
                </a>
                <nav aria-label="{{ __('ui.nav.breadcrumb') }}" class="hidden min-w-0 flex-1 lg:block">
                    <ol class="flex items-center gap-2 text-sm font-semibold text-slate-600">
                        <li><a href="{{ route('dashboard') }}" class="hover:text-slate-900">{{ __('ui.brand.product') }}</a></li>
                        @if ($plainTitle !== null)
                            <li aria-hidden="true" class="opacity-50">/</li>
                            <li class="min-w-0 truncate text-slate-900" aria-current="page">{{ $plainTitle }}</li>
                        @endif
                    </ol>
                </nav>
                <div class="flex-1 lg:hidden"></div>

                {{-- Locale switcher: POST so it never changes state on GET. --}}
                <form method="POST" action="{{ route('locale.update', $otherLocale) }}">
                    @csrf
                    <button type="submit" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-800 shadow-xs transition-colors hover:border-brand-300 hover:text-brand-800" lang="{{ $otherLocale }}" title="{{ __('ui.common.language_switch') }}">
                        <x-ui.icon name="globe" class="size-4" />
                        <span>{{ $otherLocale === 'ar' ? 'العربية' : 'English' }}</span>
                    </button>
                </form>

                @if ($user)
                    <div class="relative" x-data="{ open: false }" x-on:keydown.escape="open = false" x-on:click.outside="open = false">
                        <button type="button" class="inline-flex min-h-10 items-center gap-2 rounded-lg px-1.5 text-sm font-semibold text-slate-800 transition-colors hover:bg-white" x-on:click="open = !open" x-bind:aria-expanded="open.toString()" aria-haspopup="true">
                            <span class="ea-avatar size-8 text-sm" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                            <span class="hidden max-w-40 truncate sm:inline" dir="auto">{{ $user->name }}</span>
                            <x-ui.icon name="chevron-down" class="size-4 text-slate-600" />
                        </button>
                        <div x-show="open" x-cloak
                             x-transition:enter="transition duration-150 ease-out" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                             x-transition:leave="transition duration-100 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                             class="absolute end-0 z-30 mt-2 w-64 rounded-xl border border-slate-200 bg-white p-2 shadow-lg">
                            <div class="px-3 py-2">
                                <p class="truncate text-sm font-bold text-slate-900" dir="auto">{{ $user->name }}</p>
                                <p class="truncate text-xs text-slate-600"><bdi dir="ltr">{{ $user->email }}</bdi></p>
                                <p class="mt-2"><x-ui.role-badge :role="$user->role" /></p>
                            </div>
                            <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-100 pt-1">
                                @csrf
                                <button type="submit" class="flex min-h-10 w-full items-center gap-2 rounded-lg px-3 text-start text-sm font-semibold text-slate-800 hover:bg-brand-50 hover:text-brand-800">
                                    <x-ui.icon name="logout" class="size-4" flip />
                                    {{ __('auth.sign_out') }}
                                </button>
                            </form>
                        </div>
                    </div>
                @endif
            </header>

            <main id="main" tabindex="-1" class="mx-auto w-full max-w-[1360px] min-w-0 flex-1 px-4 py-6 focus:outline-none sm:px-6 lg:px-8 lg:py-8">
                @if (session('status'))
                    <x-ui.alert tone="success" class="mb-4" :live="true">{{ session('status') }}</x-ui.alert>
                @endif
                {{ $slot }}
            </main>

            <footer class="border-t border-slate-200 px-4 py-4 text-center text-xs text-slate-600 sm:px-6">
                Euro Assist · {{ __('ui.brand.product') }} · {{ __('ui.brand.staff_only') }}
            </footer>
        </div>
    </div>

    @livewireScripts
</body>
</html>
