<x-layouts.guest :title="__('auth.forgot')">
    @if (session('status'))
        <x-ui.alert tone="success" class="mb-4" :live="true">{{ session('status') }}</x-ui.alert>
    @endif
    <form method="POST" action="{{ route('password.email') }}" class="space-y-4" novalidate>
        @csrf
        <x-ui.field name="email" type="email" :label="__('auth.email')" :value="old('email')" required autofocus dir="ltr" />
        <x-ui.button type="submit" class="w-full">{{ __('auth.send_link') }}</x-ui.button>
        <p class="text-center text-sm"><a class="inline-block py-2 font-medium text-teal-800 underline underline-offset-2" href="{{ route('login') }}">{{ __('auth.back_to_login') }}</a></p>
    </form>
</x-layouts.guest>
