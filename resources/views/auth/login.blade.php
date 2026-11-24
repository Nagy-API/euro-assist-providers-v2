<x-layouts.guest :title="__('auth.sign_in')">
    <x-slot:subtitle>{{ __('auth.welcome') }}</x-slot:subtitle>
    @if (session('status'))
        <x-ui.alert tone="success" class="mb-4" :live="true">{{ session('status') }}</x-ui.alert>
    @endif
    <form method="POST" action="{{ route('login.store') }}" class="space-y-5" novalidate>
        @csrf
        <x-ui.field name="email" type="email" :label="__('auth.email')" :value="old('email')" required autofocus autocomplete="username" dir="ltr" />
        {{-- The password is always typed left-to-right, so the toggle sits on the PHYSICAL right of the
             LTR input in both Arabic and English (logical end-* would put it over the first characters in RTL). --}}
        <div x-data="{ show: false }" class="relative">
            <x-ui.field name="password" type="password" :label="__('auth.password')" required autocomplete="current-password" dir="ltr" x-bind:type="show ? 'text' : 'password'" class="[&_input]:pr-11" />
            <button type="button" x-on:click="show = !show" x-bind:aria-pressed="show.toString()" class="absolute right-1 top-[2.05rem] inline-flex size-9 items-center justify-center rounded-lg text-slate-600 hover:text-slate-900" aria-label="{{ __('auth.show_password') }}">
                <x-ui.icon name="eye" class="size-5" />
            </button>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <label class="inline-flex items-center gap-2 text-sm text-slate-800">
                <input id="remember" type="checkbox" name="remember" value="1" class="size-5 rounded border-slate-500">
                {{ __('auth.remember') }}
            </label>
        </div>
        <x-ui.button type="submit" class="min-h-12 w-full bg-[image:var(--ea-hero)] text-base">
            {{ __('auth.sign_in') }}
            <x-ui.icon name="chevron-right" class="size-4" flip />
        </x-ui.button>
    </form>
</x-layouts.guest>
