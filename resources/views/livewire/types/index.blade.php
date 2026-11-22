@php $editing = $editingId !== null; @endphp
<div>
    <x-ui.page-header :title="__('ui.nav.types')" :subtitle="__('ui.types.subtitle')">
        <x-slot:actions>
            <x-ui.button icon="plus" wire:click="create">{{ __('ui.types.add') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.alert tone="info" class="mb-4">{{ __('ui.types.no_delete_note') }}</x-ui.alert>

    @if ($types->isEmpty())
        <div class="ea-card"><x-ui.empty-state :title="__('ui.types.none')" icon="tag" /></div>
    @else
        <x-ui.table :caption="__('ui.nav.types')">
            <thead>
                <tr>
                    <x-ui.th>{{ __('ui.types.key') }}</x-ui.th>
                    <x-ui.th>{{ __('ui.types.name_en') }}</x-ui.th>
                    <x-ui.th>{{ __('ui.types.name_ar') }}</x-ui.th>
                    <x-ui.th>{{ __('ui.types.usage') }}</x-ui.th>
                    <x-ui.th class="hidden sm:table-cell">{{ __('ui.types.sort_order') }}</x-ui.th>
                    <x-ui.th>{{ __('ui.common.status') }}</x-ui.th>
                    <x-ui.th><span class="sr-only">{{ __('ui.common.actions') }}</span></x-ui.th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($types as $type)
                    @php
                        $en = $type->translations->firstWhere('locale', 'en')?->name;
                        $ar = $type->translations->firstWhere('locale', 'ar')?->name;
                    @endphp
                    <tr wire:key="type-{{ $type->id }}">
                        <td class="px-3 py-3"><code class="ltr-text rounded bg-slate-100 px-1.5 py-0.5 text-xs">{{ $type->key }}</code></td>
                        <td class="px-3 py-3" lang="en" dir="ltr">{{ $en ?: '—' }}</td>
                        <td class="px-3 py-3" lang="ar" dir="rtl">{{ $ar ?: '—' }}</td>
                        <td class="px-3 py-3">
                            <a class="text-brand-700 underline decoration-brand-200 underline-offset-4 hover:decoration-brand-600" href="{{ route('providers.index', ['type' => $type->id]) }}"><span class="ltr-text">{{ number_format($type->providers_count) }}</span></a>
                            @if ($type->providers_with_deleted_count > $type->providers_count)
                                <span class="block text-xs text-slate-600">{{ __('ui.types.incl_deleted', ['count' => $type->providers_with_deleted_count - $type->providers_count]) }}</span>
                            @endif
                        </td>
                        <td class="hidden px-3 py-3 sm:table-cell"><span class="ltr-text">{{ $type->sort_order }}</span></td>
                        <td class="px-3 py-3"><x-ui.status-badge :active="$type->is_active" /></td>
                        <td class="px-3 py-2 text-end">
                            <x-ui.button size="icon" variant="ghost" icon="pencil" wire:click="edit({{ $type->id }})" :title="__('ui.common.edit')"><span class="sr-only">{{ __('ui.common.edit') }}: {{ $en ?: $ar ?: $type->key }}</span></x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
        <div class="mt-4">{{ $types->links() }}</div>
    @endif

    @if ($showForm)
        <x-ui.modal :title="$editing ? __('ui.types.edit') : __('ui.types.add')" close="closeForm">
            <form id="type-form" wire:submit="save" novalidate class="space-y-4">
                <x-ui.field name="key" :label="__('ui.types.key')" wire:model="key" dir="ltr" required :disabled="$keyLocked" maxlength="64" autocomplete="off" data-autofocus
                            :hint="$keyLocked ? __('ui.types.key_locked_hint') : __('ui.types.key_hint')" />
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field name="name_en" :label="__('ui.types.name_en')" wire:model="name_en" dir="ltr" maxlength="255" />
                    <x-ui.field name="name_ar" :label="__('ui.types.name_ar')" wire:model="name_ar" dir="rtl" maxlength="255" />
                </div>
                <x-ui.field name="sort_order" type="number" :label="__('ui.types.sort_order')" wire:model="sort_order" min="0" max="1000000" dir="ltr" required />
                <x-ui.checkbox name="is_active" :label="__('ui.types.is_active')" :hint="__('ui.types.is_active_hint')" wire:model="is_active" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="closeForm">{{ __('ui.common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" form="type-form" wire:loading.attr="disabled" wire:target="save">{{ __('ui.common.save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <x-ui.toast :notice="$notice" />
</div>
