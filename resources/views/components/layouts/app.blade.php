@php
    $locale = app()->getLocale();
    $isRtl = in_array($locale, config('providers.rtl_locales'), true);
    $user = auth()->user();
    $sections = $user ? app(\App\Services\NavigationMenu::class)->for($user) : [];
    $otherLocale = $locale === 'ar' ? 'en' : 'ar';
    $pageTitle = isset($title) && trim((string) $title) !== '' ? trim((string) $title).' · '.config('app.name') : config('app.name');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $pageTitle }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full bg-slate-50 text-slate-900 antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:start-3 focus:top-3 focus:z-[70] focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-teal-800 focus:shadow-lg">{{ __('ui.common.skip_to_content') }}</a>

    <div x-data="{ nav: false }" x-on:keydown.escape.window="if (nav) { nav = false; $refs.navToggle.focus() }" class="min-h-screen lg:flex">
        {{-- Mobile backdrop --}}
        <div x-show="nav" x-cloak class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden" x-on:click="nav = false" aria-hidden="true"></div>

        {{-- Sidebar: static on large screens, off-canvas on small ones. --}}
        <aside id="sidebar" x-bind:class="nav ? 'flex' : 'hidden'" aria-label="{{ __('ui.nav.label') }}"
               class="fixed inset-y-0 start-0 z-40 w-72 max-w-[85vw] flex-col overflow-y-auto border-e border-slate-200 bg-white lg:sticky lg:top-0 lg:z-auto lg:flex lg:h-screen lg:w-64 lg:max-w-none lg:shrink-0">
            <div class="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-slate-200 px-4">
                <a href="{{ route('dashboard') }}" class="min-w-0 truncate text-sm font-semibold text-slate-900">{{ config('app.name') }}</a>
                <button type="button" class="inline-flex size-10 items-center justify-center rounded-md text-slate-700 hover:bg-slate-100 lg:hidden" x-on:click="nav = false; $refs.navToggle.focus()" aria-label="{{ __('ui.common.close_menu') }}">
                    <x-ui.icon name="x" />
                </button>
            </div>
            <nav class="flex-1 space-y-5 px-3 py-4">
                @foreach ($sections as $section)
                    <div>
                        <h2 class="mb-1 px-3 text-xs font-semibold text-slate-600">{{ __('ui.nav.section_'.$section['key']) }}</h2>
                        <ul class="space-y-0.5">
                            @foreach ($section['items'] as $item)
                                <li>
                                    <a href="{{ $item['url'] }}" @if($item['current']) aria-current="page" @endif @if($loop->first && $loop->parent->first) x-ref="sidebarFirst" @endif
                                       class="flex min-h-10 items-center gap-3 rounded-md px-3 py-2 text-sm font-medium {{ $item['current'] ? 'bg-teal-50 text-teal-900 ring-1 ring-inset ring-teal-200' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                                        <x-ui.icon :name="$item['icon']" class="size-5 {{ $item['current'] ? 'text-teal-700' : 'text-slate-500' }}" />
                                        <span>{{ $item['label'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </nav>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/95 px-4 backdrop-blur sm:px-6">
                <button type="button" x-ref="navToggle" class="inline-flex size-10 items-center justify-center rounded-md text-slate-700 hover:bg-slate-100 lg:hidden"
                        x-on:click="nav = !nav; if (nav) $nextTick(() => $refs.sidebarFirst?.focus())" x-bind:aria-expanded="nav.toString()" aria-controls="sidebar" aria-label="{{ __('ui.common.open_menu') }}">
                    <x-ui.icon name="menu" />
                </button>
                <div class="min-w-0 flex-1 truncate text-sm font-semibold text-slate-800 lg:hidden">{{ config('app.name') }}</div>
                <div class="hidden flex-1 lg:block"></div>

                {{-- Locale switcher: POST so it never changes state on GET. --}}
                <form method="POST" action="{{ route('locale.update', $otherLocale) }}">
                    @csrf
                    <button type="submit" class="inline-flex min-h-10 items-center gap-2 rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-800 hover:bg-slate-50" lang="{{ $otherLocale }}" title="{{ __('ui.common.language_switch') }}">
                        <x-ui.icon name="globe" class="size-4" />
                        <span>{{ $otherLocale === 'ar' ? 'العربية' : 'English' }}</span>
                    </button>
                </form>

                @if ($user)
                    <div class="relative" x-data="{ open: false }" x-on:keydown.escape="open = false" x-on:click.outside="open = false">
                        <button type="button" class="inline-flex min-h-10 items-center gap-2 rounded-md px-2 text-sm font-medium text-slate-800 hover:bg-slate-100" x-on:click="open = !open" x-bind:aria-expanded="open.toString()" aria-haspopup="true">
                            <span class="inline-flex size-8 items-center justify-center rounded-full bg-teal-700 text-sm font-semibold text-white" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                            <span class="hidden max-w-40 truncate sm:inline" dir="auto">{{ $user->name }}</span>
                            <x-ui.icon name="chevron-down" class="size-4" />
                        </button>
                        <div x-show="open" x-cloak class="absolute end-0 z-30 mt-2 w-64 rounded-lg border border-slate-200 bg-white p-2 shadow-lg">
                            <div class="px-3 py-2">
                                <p class="truncate text-sm font-semibold text-slate-900" dir="auto">{{ $user->name }}</p>
                                <p class="truncate text-xs text-slate-600"><bdi dir="ltr">{{ $user->email }}</bdi></p>
                                <p class="mt-2"><x-ui.role-badge :role="$user->role" /></p>
                            </div>
                            <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-100 pt-1">
                                @csrf
                                <button type="submit" class="flex min-h-10 w-full items-center gap-2 rounded-md px-3 text-start text-sm font-medium text-slate-800 hover:bg-slate-100">
                                    <x-ui.icon name="logout" class="size-4" flip />
                                    {{ __('auth.sign_out') }}
                                </button>
                            </form>
                        </div>
                    </div>
                @endif
            </header>

            <main id="main" tabindex="-1" class="mx-auto w-full min-w-0 max-w-7xl flex-1 px-4 py-6 focus:outline-none sm:px-6 lg:px-8">
                @if (session('status'))
                    <x-ui.alert tone="success" class="mb-4" :live="true">{{ session('status') }}</x-ui.alert>
                @endif
                {{ $slot }}
            </main>
        </div>
    </div>

    @livewireScripts
</body>
</html>
