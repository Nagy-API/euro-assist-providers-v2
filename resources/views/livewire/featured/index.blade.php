@php $editingNow = $editingId !== null; @endphp
<div>
    <x-ui.page-header :title="__('ui.nav.featured')" :subtitle="__('ui.featured.subtitle')">
        @if ($manage)
            <x-slot:actions>
                <x-ui.button icon="plus" wire:click="create">{{ __('ui.featured.add') }}</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @unless ($manage)
        <x-ui.alert tone="info" class="mb-4">{{ __('ui.featured.read_only') }}</x-ui.alert>
    @endunless

    @if ($items->isEmpty())
        <div class="ea-card"><x-ui.empty-state :title="__('ui.featured.none')" icon="star">{{ __('ui.featured.none_hint') }}</x-ui.empty-state></div>
    @else
        <x-ui.table :caption="__('ui.nav.featured')">
            <thead>
                <tr>
                    <x-ui.th>{{ __('ui.featured.logo') }}</x-ui.th>
                    <x-ui.th>{{ __('ui.common.name') }}</x-ui.th>
                    <x-ui.th class="hidden sm:table-cell">{{ __('ui.providers.type') }}</x-ui.th>
                    <x-ui.th class="hidden sm:table-cell">
                        {{ __('ui.featured.sort_order') }} <x-ui.badge tone="violet">{{ __('ui.featured.v2_option') }}</x-ui.badge>
                    </x-ui.th>
                    <x-ui.th>{{ __('ui.common.status') }} <x-ui.badge tone="violet">{{ __('ui.featured.v2_option') }}</x-ui.badge></x-ui.th>
                    @if ($manage)<x-ui.th><span class="sr-only">{{ __('ui.common.actions') }}</span></x-ui.th>@endif
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($items as $item)
                    <tr wire:key="featured-{{ $item->id }}">
                        <td class="px-3 py-3">
                            @if (filled($item->logo_path))
                                <img src="{{ route('featured.logo', $item) }}?v={{ $item->updated_at?->timestamp }}" alt="" width="48" height="48" loading="lazy" class="size-12 rounded-lg border border-slate-200 bg-white object-contain">
                            @else
                                <span class="inline-flex size-12 items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50 text-slate-500" title="{{ __('ui.featured.no_logo') }}"><x-ui.icon name="photo" /><span class="sr-only">{{ __('ui.featured.no_logo') }}</span></span>
                            @endif
                        </td>
                        <td class="px-3 py-3">
                            <p class="font-medium text-slate-900" dir="auto"><bdi>{{ $item->name($locale) }}</bdi></p>
                            <p class="text-xs text-slate-600">
                                @if (filled($item->name_en))<span lang="en" dir="ltr">EN: {{ $item->name_en }}</span>@endif
                                @if (filled($item->name_en) && filled($item->name_ar)) · @endif
                                @if (filled($item->name_ar))<span lang="ar" dir="rtl">AR: {{ $item->name_ar }}</span>@endif
                            </p>
                        </td>
                        <td class="hidden px-3 py-3 sm:table-cell" dir="auto">{{ $item->type?->label($locale) ?? '—' }}</td>
                        <td class="hidden px-3 py-3 sm:table-cell"><span class="ltr-text">{{ $item->sort_order }}</span></td>
                        <td class="px-3 py-3"><x-ui.status-badge :active="$item->is_active" /></td>
                        @if ($manage)
                            <td class="px-3 py-2 text-end whitespace-nowrap">
                                <x-ui.button size="icon" variant="ghost" icon="pencil" wire:click="edit({{ $item->id }})" :title="__('ui.common.edit')"><span class="sr-only">{{ __('ui.common.edit') }}: {{ $item->name($locale) }}</span></x-ui.button>
                                <x-ui.button size="icon" variant="ghost" class="text-red-800" icon="trash" wire:click="confirmDelete({{ $item->id }})" :title="__('ui.common.delete')"><span class="sr-only">{{ __('ui.common.delete') }}: {{ $item->name($locale) }}</span></x-ui.button>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
        <div class="mt-4">{{ $items->links() }}</div>
    @endif

    @if ($manage && $showForm)
        <x-ui.modal :title="$editingNow ? __('ui.featured.edit') : __('ui.featured.add')" close="closeForm" size="lg">
            <form id="featured-form" wire:submit="save" novalidate class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field name="name_en" :label="__('ui.featured.name_en')" wire:model="name_en" dir="ltr" maxlength="255" data-autofocus :hint="__('ui.featured.name_hint')" />
                    <x-ui.field name="name_ar" :label="__('ui.featured.name_ar')" wire:model="name_ar" dir="rtl" maxlength="255" />
                </div>
                <x-ui.field name="provider_type_id" type="select" :label="__('ui.featured.optional_type')" wire:model="provider_type_id">
                    <option value="">{{ __('ui.common.none') }}</option>
                    @foreach ($types as $t)<option value="{{ $t->id }}">{{ $t->label($locale) }}</option>@endforeach
                </x-ui.field>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field name="sort_order" type="number" :label="__('ui.featured.sort_order')" wire:model="sort_order" min="0" max="1000000" dir="ltr" required :hint="__('ui.featured.v2_hint')" />
                    <div class="self-end pb-1"><x-ui.checkbox name="is_active" :label="__('ui.featured.is_active')" :hint="__('ui.featured.v2_hint')" wire:model="is_active" /></div>
                </div>

                <div>
                    <label for="f-logo" class="mb-1 block text-sm font-medium text-slate-800">{{ __('ui.featured.logo') }}</label>
                    @if ($editing && filled($editing->logo_path) && ! $removeLogo && $logo === null)
                        <div class="mb-2 flex items-center gap-3">
                            <img src="{{ route('featured.logo', $editing) }}?v={{ $editing->updated_at?->timestamp }}" alt="" width="64" height="64" class="size-16 rounded-lg border border-slate-200 object-contain">
                            <x-ui.checkbox name="removeLogo" :label="__('ui.featured.remove_logo')" wire:model="removeLogo" />
                        </div>
                    @endif
                    <input id="f-logo" type="file" wire:model="logo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" aria-describedby="logo-hint @error('logo') logo-error @enderror"
                           class="block w-full text-sm text-slate-800 file:me-3 file:min-h-10 file:cursor-pointer file:rounded-lg file:border file:border-brand-200 file:bg-brand-50 file:px-4 file:text-sm file:font-bold file:text-brand-800 hover:file:bg-brand-100">
                    <p id="logo-hint" class="mt-1 text-xs text-slate-600">{{ __('ui.featured.logo_hint', ['kb' => $maxKb]) }}</p>
                    <div wire:loading wire:target="logo" class="mt-1"><x-ui.loading /></div>
                    @error('logo')<p id="logo-error" class="mt-1 text-sm font-medium text-red-800" role="alert">{{ $message }}</p>@enderror
                    @if ($logo !== null && ! $errors->has('logo'))
                        <p class="mt-1 text-xs font-medium text-green-900">{{ __('ui.featured.logo_ready') }}</p>
                    @endif
                </div>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="closeForm">{{ __('ui.common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" form="featured-form" wire:loading.attr="disabled" wire:target="save,logo">{{ __('ui.common.save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($manage && $deleting)
        <x-ui.modal :title="__('ui.featured.delete_title')" close="cancelDelete" size="sm">
            <p class="text-sm text-slate-700">{{ __('ui.featured.delete_body', ['name' => $deleting->name($locale)]) }}</p>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="cancelDelete">{{ __('ui.common.cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="performDelete" data-autofocus>{{ __('ui.common.delete') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <x-ui.toast :notice="$notice" />
</div>
