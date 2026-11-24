<x-layouts.guest :title="__('auth.change_title')">
    <x-slot:subtitle>{{ __('auth.change_subtitle') }}</x-slot:subtitle>
    <x-ui.alert tone="warning" class="mb-4">{{ __('auth.change_required') }}</x-ui.alert>
    <form method="POST" action="{{ route('password.change.update') }}" class="space-y-4" novalidate>
        @csrf
        <x-ui.field name="current_password" type="password" :label="__('auth.current_password')" required autocomplete="current-password" dir="ltr" data-autofocus />
        <x-ui.field name="password" type="password" :label="__('auth.new_password')" required autocomplete="new-password" dir="ltr" :hint="__('auth.password_rules')" />
        <x-ui.field name="password_confirmation" type="password" :label="__('auth.confirm_password')" required autocomplete="new-password" dir="ltr" />
        <x-ui.button type="submit" class="w-full">{{ __('auth.change_submit') }}</x-ui.button>
    </form>
    <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
        @csrf
        <button type="submit" class="inline-flex min-h-10 items-center rounded-lg px-3 text-sm font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-brand-800">{{ __('auth.sign_out') }}</button>
    </form>
</x-layouts.guest>
