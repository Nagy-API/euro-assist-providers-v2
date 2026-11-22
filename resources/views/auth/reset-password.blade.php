<x-layouts.guest :title="__('auth.reset_title')">
    <form method="POST" action="{{ route('password.update') }}" class="space-y-4" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-ui.field name="email" type="email" :label="__('auth.email')" :value="old('email', $email)" required dir="ltr" />
        <x-ui.field name="password" type="password" :label="__('auth.new_password')" required autocomplete="new-password" dir="ltr" />
        <x-ui.field name="password_confirmation" type="password" :label="__('auth.confirm_password')" required autocomplete="new-password" dir="ltr" />
        <x-ui.button type="submit" class="w-full">{{ __('auth.reset_title') }}</x-ui.button>
    </form>
</x-layouts.guest>
