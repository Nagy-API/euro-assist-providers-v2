<div>
    <x-ui.page-header :title="__('ui.nav.users')" :subtitle="__('ui.users.subtitle')">
        <x-slot:actions>
            <x-ui.button icon="plus" wire:click="openCreate">{{ __('ui.users.create') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form wire:submit.prevent class="mb-4 max-w-md" role="search">
        <x-ui.field name="search" id="user-search" :label="__('ui.common.search')" wire:model.live.debounce.400ms="search" :hint="__('ui.users.search_hint')" maxlength="100" autocomplete="off" />
    </form>

    <x-ui.alert tone="info" class="mb-4">{{ __('ui.users.no_delete_note') }}</x-ui.alert>

    @if ($users->isEmpty())
        <div class="ea-card"><x-ui.empty-state :title="__('ui.users.none')" icon="users" /></div>
    @else
        <x-ui.table :caption="__('ui.nav.users')">
            <thead>
                <tr>
                    <x-ui.th>{{ __('ui.common.name') }}</x-ui.th>
                    <x-ui.th class="hidden sm:table-cell">{{ __('ui.users.email') }}</x-ui.th>
                    <x-ui.th>{{ __('ui.users.role') }}</x-ui.th>
                    <x-ui.th>{{ __('ui.common.status') }}</x-ui.th>
                    <x-ui.th class="hidden md:table-cell">{{ __('ui.users.last_login') }}</x-ui.th>
                    <x-ui.th><span class="sr-only">{{ __('ui.common.actions') }}</span></x-ui.th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($users as $user)
                    <tr wire:key="user-{{ $user->id }}">
                        <td class="px-3 py-3">
                            <span class="font-medium text-slate-900" dir="auto"><bdi>{{ $user->name }}</bdi></span>
                            @if ($user->id === $me)<x-ui.badge tone="teal">{{ __('ui.users.you') }}</x-ui.badge>@endif
                            <span class="block text-xs text-slate-600 sm:hidden"><bdi dir="ltr">{{ $user->email }}</bdi></span>
                        </td>
                        <td class="hidden px-3 py-3 sm:table-cell"><span class="ltr-text break-all">{{ $user->email }}</span></td>
                        <td class="px-3 py-3">@if ($user->role)<x-ui.role-badge :role="$user->role" />@else<x-ui.badge tone="amber">{{ __('ui.users.role_unknown') }}</x-ui.badge>@endif</td>
                        <td class="px-3 py-3">
                            <x-ui.status-badge :active="$user->is_active" />
                            @if ($user->must_change_password)
                                <x-ui.badge tone="amber" class="mt-1" :title="__('ui.users.password_change_hint')">{{ __('ui.users.password_change_required') }}</x-ui.badge>
                            @endif
                            @if ($user->requiresActivation())
                                <x-ui.badge tone="amber" class="mt-1" :title="__('ui.users.activation_pending_hint')">{{ __('ui.users.activation_pending') }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="hidden px-3 py-3 whitespace-nowrap text-slate-700 md:table-cell"><span class="ltr-text">{{ $user->last_login_at?->format('Y-m-d H:i') ?? '—' }}</span></td>
                        <td class="px-3 py-2 text-end">
                            <x-ui.button size="sm" variant="secondary" icon="pencil" wire:click="openManage({{ $user->id }})">{{ __('ui.users.manage') }}<span class="sr-only">: {{ $user->name }}</span></x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
        <div class="mt-4">{{ $users->links() }}</div>
    @endif

    @if ($dialog === 'create')
        <x-ui.modal :title="__('ui.users.create')" close="closeDialog" :description="__('ui.users.create_hint')">
            <form id="create-form" wire:submit="create" novalidate class="space-y-4">
                <x-ui.field name="name" :label="__('ui.common.name')" wire:model="name" required maxlength="255" data-autofocus autocomplete="off" />
                <x-ui.field name="email" type="email" :label="__('ui.users.email')" wire:model="email" required dir="ltr" maxlength="255" autocomplete="off" :hint="__('ui.users.email_hint')" />
                <x-ui.field name="role" type="select" :label="__('ui.users.role')" wire:model="role" required>
                    @foreach ($roles as $r)<option value="{{ $r->value }}">{{ $r->label() }}</option>@endforeach
                </x-ui.field>
                <x-ui.field name="password" type="password" :label="__('ui.users.password')" wire:model="password" required dir="ltr" maxlength="1024" autocomplete="new-password" :hint="__('auth.password_rules_new')" />
                <x-ui.field name="password_confirmation" type="password" :label="__('auth.confirm_password')" wire:model="password_confirmation" required dir="ltr" maxlength="1024" autocomplete="new-password" />
                <x-ui.checkbox name="mustChange" :label="__('ui.users.must_change')" :hint="__('ui.users.must_change_hint')" wire:model="mustChange" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="closeDialog">{{ __('ui.common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" form="create-form" wire:loading.attr="disabled" wire:target="create">{{ __('ui.users.create_submit') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($dialog === 'reset' && $target)
        <x-ui.modal :title="__('ui.users.reset_title', ['name' => $target->name])" close="closeDialog" :description="__('ui.users.reset_hint')">
            <form id="reset-form" wire:submit="resetPassword" novalidate class="space-y-4">
                <x-ui.field name="password" type="password" :label="__('auth.new_password')" wire:model="password" required dir="ltr" maxlength="1024" autocomplete="new-password" data-autofocus :hint="__('auth.password_rules_new')" />
                <x-ui.field name="password_confirmation" type="password" :label="__('auth.confirm_password')" wire:model="password_confirmation" required dir="ltr" maxlength="1024" autocomplete="new-password" />
                <x-ui.checkbox name="mustChange" :label="__('ui.users.must_change')" :hint="__('ui.users.must_change_hint')" wire:model="mustChange" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="closeDialog">{{ __('ui.common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" form="reset-form" wire:loading.attr="disabled" wire:target="resetPassword">{{ __('ui.users.reset_submit') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($dialog === 'manage' && $target)
        <x-ui.modal :title="__('ui.users.manage_title', ['name' => $target->name])" close="closeDialog">
            <form id="manage-form" wire:submit="saveManage" novalidate class="space-y-4">
                @if ($target->id === $me)
                    <x-ui.alert tone="warning">{{ __('ui.users.self_warning') }}</x-ui.alert>
                @endif
                <x-ui.field name="manageRole" type="select" :label="__('ui.users.role')" wire:model="manageRole" data-autofocus>
                    @foreach ($roles as $r)<option value="{{ $r->value }}">{{ $r->label() }}</option>@endforeach
                </x-ui.field>
                <x-ui.checkbox name="manageActive" :label="__('ui.users.account_active')" :hint="__('ui.users.deactivate_hint')" wire:model="manageActive" />
                @if ($target->id !== $me)
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <p class="text-sm font-semibold text-slate-900">{{ __('ui.users.reset_section') }}</p>
                        <p class="mt-1 text-xs text-slate-600">{{ __('ui.users.reset_section_hint') }}</p>
                        <x-ui.button class="mt-2" size="sm" variant="secondary" type="button" wire:click="openReset({{ $target->id }})">{{ __('ui.users.reset_open') }}</x-ui.button>
                    </div>
                @endif
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="closeDialog">{{ __('ui.common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" form="manage-form" wire:loading.attr="disabled" wire:target="saveManage">{{ __('ui.common.save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <x-ui.toast :notice="$notice" />
</div>
