<x-layouts.app :title="__('auth.account_password_title')">
    <x-ui.page-header :title="__('auth.account_password_title')" :subtitle="__('auth.account_password_subtitle')" />

    <div class="ea-card max-w-xl p-5 sm:p-6">
        <form method="POST" action="{{ route('password.change.update') }}" class="space-y-4" novalidate>
            @csrf
            <x-ui.field name="current_password" type="password" :label="__('auth.current_password_own')" required autocomplete="current-password" dir="ltr" data-autofocus />
            <x-ui.field name="password" type="password" :label="__('auth.new_password')" required autocomplete="new-password" dir="ltr" :hint="__('auth.password_rules_new')" />
            <x-ui.field name="password_confirmation" type="password" :label="__('auth.confirm_password')" required autocomplete="new-password" dir="ltr" />
            <x-ui.alert tone="info">{{ __('auth.other_sessions_note') }}</x-ui.alert>
            <x-ui.button type="submit">{{ __('auth.change_submit') }}</x-ui.button>
        </form>
    </div>
</x-layouts.app>
