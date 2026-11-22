<x-layouts.guest :title="__('auth.sign_in')">
    @if (session('status'))
        <x-ui.alert tone="success" class="mb-4" :live="true">{{ session('status') }}</x-ui.alert>
    @endif
    <form method="POST" action="{{ route('login.store') }}" class="space-y-4" novalidate>
        @csrf
        <x-ui.field name="email" type="email" :label="__('auth.email')" :value="old('email')" required autofocus autocomplete="username" dir="ltr" />
        <x-ui.field name="password" type="password" :label="__('auth.password')" required autocomplete="current-password" dir="ltr" />
        <div class="flex items-center gap-2">
            <input id="remember" type="checkbox" name="remember" value="1" class="size-5 rounded border-slate-500 text-teal-700">
            <label for="remember" class="text-sm text-slate-800">{{ __('auth.remember') }}</label>
        </div>
        <x-ui.button type="submit" class="w-full">{{ __('auth.sign_in') }}</x-ui.button>
        <p class="text-center text-sm"><a class="inline-block py-2 font-medium text-teal-800 underline underline-offset-2" href="{{ route('password.request') }}">{{ __('auth.forgot') }}</a></p>
    </form>
</x-layouts.guest>
