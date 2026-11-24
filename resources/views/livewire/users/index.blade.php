<div>
    <x-ui.page-header :title="__('ui.nav.users')" :subtitle="__('ui.users.subtitle')">
        <x-slot:actions>
            <x-ui.button icon="plus" wire:click="openInvite">{{ __('ui.users.invite') }}</x-ui.button>
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
                        <td class="px-3 py-3"><x-ui.role-badge :role="$user->role" /></td>
                        <td class="px-3 py-3">
                            <x-ui.status-badge :active="$user->is_active" />
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

    @if ($dialog === 'invite')
        <x-ui.modal :title="__('ui.users.invite')" close="closeDialog" :description="__('ui.users.invite_hint')">
            <form id="invite-form" wire:submit="invite" novalidate class="space-y-4">
                <x-ui.field name="name" :label="__('ui.common.name')" wire:model="name" required maxlength="255" data-autofocus autocomplete="off" />
                <x-ui.field name="email" type="email" :label="__('ui.users.email')" wire:model="email" required dir="ltr" maxlength="255" autocomplete="off" />
                <x-ui.field name="role" type="select" :label="__('ui.users.role')" wire:model="role" required>
                    @foreach ($roles as $r)<option value="{{ $r->value }}">{{ $r->label() }}</option>@endforeach
                </x-ui.field>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="closeDialog">{{ __('ui.common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" form="invite-form" wire:loading.attr="disabled" wire:target="invite">{{ __('ui.users.send_invite') }}</x-ui.button>
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
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="closeDialog">{{ __('ui.common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" form="manage-form" wire:loading.attr="disabled" wire:target="saveManage">{{ __('ui.common.save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <x-ui.toast :notice="$notice" />
</div>
