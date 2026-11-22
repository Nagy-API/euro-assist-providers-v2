@php
    $canCreate = Gate::allows('providers.create');
    $canEdit = Gate::allows('providers.update');
    $canDelete = Gate::allows('providers.delete');
    $canRestore = Gate::allows('providers.restore');
    $canBulkWrite = Gate::allows('providers.bulk.write');
    $canBulkDelete = Gate::allows('providers.bulk.delete');
    $canExport = Gate::allows('exports.run');
    $selectedCount = count($selected);
    $showCheckboxes = $canBulkWrite || $canBulkDelete || $canExport;
@endphp
<div>
    <x-ui.page-header :title="__('ui.providers.title')" :subtitle="__('ui.providers.subtitle')">
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button variant="secondary" icon="download" wire:click="exportFiltered" wire:loading.attr="disabled" wire:target="exportFiltered">{{ __('ui.providers.export_filtered') }}</x-ui.button>
            @endif
            @if ($canCreate)
                <x-ui.button :href="route('providers.create')" icon="plus">{{ __('ui.nav.add_provider') }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Filters --}}
    <form wire:submit.prevent class="mb-5 ea-card animate-enter p-5" role="search" aria-label="{{ __('ui.providers.filters') }}">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" x-data="{ more: {{ $hasFilters ? 'true' : 'false' }} }">
            <x-ui.field name="search" id="flt-search" :label="__('ui.common.search')" wire:model.live.debounce.400ms="search" :hint="__('ui.providers.search_hint')" maxlength="100" autocomplete="off" type="search" class="sm:col-span-2" />
            {{-- Phones: the secondary filters fold away so the results are visible without scrolling. --}}
            <button type="button" class="inline-flex min-h-10 items-center justify-between gap-2 rounded-lg border border-slate-300 bg-slate-50 px-3 text-sm font-semibold text-slate-800 sm:hidden" x-on:click="more = !more" x-bind:aria-expanded="more.toString()" aria-controls="more-filters">
                <span class="inline-flex items-center gap-2"><x-ui.icon name="tag" class="size-4" />{{ __('ui.providers.more_filters') }}</span>
                <x-ui.icon name="chevron-down" class="size-4 transition-transform" x-bind:class="more && 'rotate-180'" />
            </button>
            <div id="more-filters" class="contents max-sm:hidden" x-bind:class="more && 'max-sm:!contents'">
            <x-ui.field name="type" id="flt-type" type="select" :label="__('ui.providers.type')" wire:model.live="type">
                <option value="">{{ __('ui.common.all') }}</option>
                @foreach ($types as $t)
                    <option value="{{ $t->id }}">{{ $t->label($locale) }}@unless ($t->is_active) ({{ __('ui.status.inactive') }})@endunless</option>
                @endforeach
            </x-ui.field>
            <x-ui.field name="status" id="flt-status" type="select" :label="__('ui.common.status')" wire:model.live="status">
                <option value="">{{ __('ui.common.all') }}</option>
                <option value="active">{{ __('ui.status.active') }}</option>
                <option value="inactive">{{ __('ui.status.inactive') }}</option>
            </x-ui.field>
            <x-ui.field name="city" id="flt-city" :label="__('ui.providers.city')" wire:model.live.debounce.500ms="city" list="city-options" autocomplete="off" :hint="__('ui.providers.exact_match')" />
            <x-ui.field name="district" id="flt-district" :label="__('ui.providers.district')" wire:model.live.debounce.500ms="district" list="district-options" autocomplete="off" />
            @if ($canSeeTrashed)
                <x-ui.field name="trashed" id="flt-trashed" type="select" :label="__('ui.providers.deleted_filter')" wire:model.live="trashed">
                    <option value="">{{ __('ui.providers.trashed_without') }}</option>
                    <option value="with">{{ __('ui.providers.trashed_with') }}</option>
                    <option value="only">{{ __('ui.providers.trashed_only') }}</option>
                </x-ui.field>
            @endif
            <x-ui.field name="perPage" id="flt-per-page" type="select" :label="__('ui.common.per_page')" wire:model.live="perPage">
                @foreach ($perPageOptions as $n)
                    <option value="{{ $n }}" @selected($n === $perPageCurrent)>{{ $n }}</option>
                @endforeach
            </x-ui.field>
            </div>
        </div>
        <datalist id="city-options">@foreach ($cities as $c)<option value="{{ $c }}"></option>@endforeach</datalist>
        <datalist id="district-options">@foreach ($districts as $d)<option value="{{ $d }}"></option>@endforeach</datalist>
        @if ($hasFilters)
            <div class="mt-3"><x-ui.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">{{ __('ui.common.clear_filters') }}</x-ui.button></div>
        @endif
    </form>

    {{-- Bulk bar --}}
    @if ($selectedCount > 0)
        <div class="mb-4 flex flex-col gap-3 animate-enter rounded-xl border border-brand-200 bg-brand-50 p-3 shadow-xs sm:flex-row sm:items-center sm:justify-between" role="region" aria-label="{{ __('ui.providers.bulk_bar') }}">
            <p class="text-sm font-semibold text-brand-950">{{ __('ui.providers.selected_count', ['count' => $selectedCount]) }}</p>
            <div class="flex flex-wrap gap-2">
                @if ($canBulkWrite)
                    <x-ui.button size="sm" variant="secondary" icon="pencil" wire:click="openBulk">{{ __('ui.providers.bulk_update') }}</x-ui.button>
                @endif
                @if ($canExport)
                    <x-ui.button size="sm" variant="secondary" icon="download" wire:click="exportSelected">{{ __('ui.providers.export_selected') }}</x-ui.button>
                @endif
                @if ($canBulkDelete)
                    <x-ui.button size="sm" variant="danger-outline" icon="trash" wire:click="openBulkDelete">{{ __('ui.providers.bulk_delete') }}</x-ui.button>
                    @if ($canSeeTrashed && $trashed !== '')
                        <x-ui.button size="sm" variant="secondary" icon="restore" wire:click="openBulkRestore">{{ __('ui.providers.bulk_restore') }}</x-ui.button>
                    @endif
                @endif
                <x-ui.button size="sm" variant="ghost" wire:click="clearSelection">{{ __('ui.providers.clear_selection') }}</x-ui.button>
            </div>
        </div>
    @endif

    <div wire:loading.delay.short wire:target="search,type,city,district,status,trashed,perPage,sortBy,gotoPage,nextPage,previousPage,clearFilters" class="mb-2">
        <x-ui.loading />
    </div>

    @if ($providers->isEmpty())
        <div class="ea-card">
            @if ($hasFilters)
                <x-ui.empty-state :title="__('ui.providers.no_results')" icon="search">
                    {{ __('ui.providers.no_results_hint') }}
                    <div class="mt-3"><x-ui.button variant="secondary" size="sm" wire:click="clearFilters">{{ __('ui.common.clear_filters') }}</x-ui.button></div>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state :title="__('ui.providers.none_yet')" icon="building">
                    {{ __('ui.providers.none_yet_hint') }}
                    @if ($canCreate)
                        <div class="mt-3"><x-ui.button :href="route('providers.create')" size="sm" icon="plus">{{ __('ui.nav.add_provider') }}</x-ui.button></div>
                    @endif
                </x-ui.empty-state>
            @endif
        </div>
    @else
        <x-ui.table :caption="__('ui.providers.title')" wire:loading.class.delay="opacity-60" wire:target="search,type,city,district,status,trashed,perPage,sortBy,gotoPage,nextPage,previousPage,clearFilters" class="transition-opacity">
            <thead>
                <tr>
                    @if ($showCheckboxes)
                        <th scope="col" class="w-10 bg-slate-50 px-3 py-2.5">
                            <input type="checkbox" wire:model.live="selectPage" class="size-5 rounded border-slate-500 text-brand-700" aria-label="{{ __('ui.providers.select_page') }}">
                        </th>
                    @endif
                    <x-ui.th sort="name" :current="$sortCurrent" :dir="$dirCurrent">{{ __('ui.providers.name') }}</x-ui.th>
                    <x-ui.th sort="type" :current="$sortCurrent" :dir="$dirCurrent" class="hidden md:table-cell">{{ __('ui.providers.type') }}</x-ui.th>
                    <x-ui.th sort="city" :current="$sortCurrent" :dir="$dirCurrent" class="hidden md:table-cell">{{ __('ui.providers.city') }}</x-ui.th>
                    <x-ui.th class="hidden lg:table-cell">{{ __('ui.providers.phone') }}</x-ui.th>
                    <x-ui.th>{{ __('ui.common.status') }}</x-ui.th>
                    <x-ui.th sort="updated_at" :current="$sortCurrent" :dir="$dirCurrent" class="hidden xl:table-cell">{{ __('ui.providers.updated_at') }}</x-ui.th>
                    <x-ui.th><span class="sr-only">{{ __('ui.common.actions') }}</span></x-ui.th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($providers as $provider)
                    @php
                        $tr = $provider->displayTranslation($locale);
                        $fallback = $tr !== null && $tr->locale !== $locale;
                        $typeLabel = $provider->type?->label($locale);
                    @endphp
                    <tr wire:key="provider-{{ $provider->id }}" class="{{ $provider->trashed() ? 'bg-red-50/50' : '' }} align-top transition-colors hover:bg-slate-50">
                        @if ($showCheckboxes)
                            <td class="px-3 py-3">
                                <input type="checkbox" wire:model.live="selected" value="{{ $provider->id }}" class="size-5 rounded border-slate-500 text-brand-700" aria-label="{{ __('ui.providers.select_row', ['name' => $tr?->name ?? '#'.$provider->id]) }}">
                            </td>
                        @endif
                        <td class="max-w-xs px-3 py-3">
                            <a href="{{ route('providers.show', $provider) }}" class="font-bold text-slate-900 transition-colors hover:text-brand-700 hover:underline"><bdi dir="auto">{{ $tr?->name ?? '—' }}</bdi></a>
                            <div class="mt-1 flex flex-wrap items-center gap-1">
                                <x-ui.translation-badges :provider="$provider" />
                                @if ($fallback)
                                    <x-ui.badge tone="amber" :title="__('ui.locale.fallback_hint', ['language' => __('ui.locale.'.$tr->locale)])">{{ __('ui.locale.shown_'.$tr->locale) }}</x-ui.badge>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-slate-600 md:hidden"><bdi dir="auto">{{ $typeLabel ?? '—' }}</bdi> · <bdi dir="auto">{{ $tr?->city ?: '—' }}</bdi></p>
                        </td>
                        <td class="hidden px-3 py-3 text-slate-700 md:table-cell"><bdi dir="auto">{{ $typeLabel ?? '—' }}</bdi></td>
                        <td class="hidden px-3 py-3 text-slate-700 md:table-cell"><bdi dir="auto">{{ $tr?->city ?: '—' }}</bdi></td>
                        <td class="hidden px-3 py-3 whitespace-nowrap lg:table-cell">
                            @if (filled($provider->phone))<span class="ltr-text">{{ $provider->phone }}</span>@else<span aria-label="{{ __('ui.common.not_set') }}">—</span>@endif
                        </td>
                        <td class="px-3 py-3"><x-ui.status-badge :active="$provider->is_active" :deleted="$provider->trashed()" /></td>
                        <td class="hidden px-3 py-3 whitespace-nowrap text-slate-700 xl:table-cell"><span class="ltr-text">{{ $provider->updated_at?->format('Y-m-d H:i') ?? '—' }}</span></td>
                        <td class="px-3 py-2 text-end whitespace-nowrap">
                            <div class="inline-flex items-center gap-1">
                                <x-ui.button size="icon" variant="ghost" :href="route('providers.show', $provider)" icon="eye" :title="__('ui.common.view')"><span class="sr-only">{{ __('ui.common.view') }}: {{ $tr?->name ?? '#'.$provider->id }}</span></x-ui.button>
                                @if ($canEdit && ! $provider->trashed())
                                    <x-ui.button size="icon" variant="ghost" :href="route('providers.edit', $provider)" icon="pencil" :title="__('ui.common.edit')"><span class="sr-only">{{ __('ui.common.edit') }}: {{ $tr?->name ?? '#'.$provider->id }}</span></x-ui.button>
                                @endif
                                @if ($provider->trashed())
                                    @if ($canRestore)
                                        <x-ui.button size="icon" variant="ghost" icon="restore" wire:click="confirmRestore({{ $provider->id }})" :title="__('ui.common.restore')"><span class="sr-only">{{ __('ui.common.restore') }}: {{ $tr?->name ?? '#'.$provider->id }}</span></x-ui.button>
                                    @endif
                                @elseif ($canDelete)
                                    <x-ui.button size="icon" variant="ghost" class="text-red-800" icon="trash" wire:click="confirmDelete({{ $provider->id }})" :title="__('ui.common.delete')"><span class="sr-only">{{ __('ui.common.delete') }}: {{ $tr?->name ?? '#'.$provider->id }}</span></x-ui.button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>

        <div class="mt-4">{{ $providers->links() }}</div>
    @endif

    {{-- Dialogs --}}
    @if ($dialog === 'delete' || $dialog === 'restore')
        <x-ui.modal :title="$dialog === 'delete' ? __('ui.providers.delete_title') : __('ui.providers.restore_title')" close="closeDialog" size="sm">
            <p class="text-sm text-slate-700">{{ $dialog === 'delete' ? __('ui.providers.delete_body') : __('ui.providers.restore_body') }}</p>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="closeDialog">{{ __('ui.common.cancel') }}</x-ui.button>
                @if ($dialog === 'delete')
                    <x-ui.button variant="danger" wire:click="performDelete" wire:loading.attr="disabled" data-autofocus>{{ __('ui.common.delete') }}</x-ui.button>
                @else
                    <x-ui.button wire:click="performRestore" wire:loading.attr="disabled" data-autofocus>{{ __('ui.common.restore') }}</x-ui.button>
                @endif
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($dialog === 'bulk_delete' || $dialog === 'bulk_restore')
        <x-ui.modal :title="$dialog === 'bulk_delete' ? __('ui.providers.bulk_delete_title') : __('ui.providers.bulk_restore_title')" close="closeDialog" size="sm">
            <p class="text-sm text-slate-700">{{ __($dialog === 'bulk_delete' ? 'ui.providers.bulk_delete_body' : 'ui.providers.bulk_restore_body', ['count' => $selectedCount]) }}</p>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="closeDialog">{{ __('ui.common.cancel') }}</x-ui.button>
                @if ($dialog === 'bulk_delete')
                    <x-ui.button variant="danger" wire:click="performBulkDelete" wire:loading.attr="disabled" data-autofocus>{{ __('ui.providers.bulk_delete_confirm', ['count' => $selectedCount]) }}</x-ui.button>
                @else
                    <x-ui.button wire:click="performBulkRestore" wire:loading.attr="disabled" data-autofocus>{{ __('ui.providers.bulk_restore_confirm', ['count' => $selectedCount]) }}</x-ui.button>
                @endif
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($dialog === 'bulk')
        <x-ui.modal :title="__('ui.providers.bulk_update')" close="closeDialog" :description="$bulkPreview === null ? __('ui.providers.bulk_form_hint', ['count' => $selectedCount]) : null">
            @if ($bulkPreview === null)
                <div class="space-y-4">
                    <x-ui.field name="bulkType" id="bulk-type" type="select" :label="__('ui.providers.type')" wire:model="bulkType" error="provider_type_id" data-autofocus>
                        <option value="">{{ __('ui.providers.no_change') }}</option>
                        @foreach ($types as $t)
                            @if ($t->is_active)<option value="{{ $t->id }}">{{ $t->label($locale) }}</option>@endif
                        @endforeach
                    </x-ui.field>
                    <x-ui.field name="bulkActive" id="bulk-active" type="select" :label="__('ui.common.status')" wire:model="bulkActive" error="is_active">
                        <option value="">{{ __('ui.providers.no_change') }}</option>
                        <option value="1">{{ __('ui.status.active') }}</option>
                        <option value="0">{{ __('ui.status.inactive') }}</option>
                    </x-ui.field>
                    @error('changes')<p class="text-sm font-medium text-red-800" role="alert">{{ $message }}</p>@enderror
                    @error('ids')<p class="text-sm font-medium text-red-800" role="alert">{{ $message }}</p>@enderror
                    @error('token')<p class="text-sm font-medium text-red-800" role="alert">{{ $message }}</p>@enderror
                </div>
            @else
                <x-ui.alert tone="warning" :title="__('ui.providers.bulk_confirm_title', ['count' => $bulkPreview['count']])">
                    <ul class="mt-1 list-disc ps-5">
                        @if ($bulkPreview['type'] !== null)<li>{{ __('ui.providers.bulk_change_type', ['type' => $bulkPreview['type']]) }}</li>@endif
                        @if ($bulkPreview['active'] !== null)<li>{{ __('ui.providers.bulk_change_status', ['status' => __('ui.status.'.$bulkPreview['active'])]) }}</li>@endif
                    </ul>
                    <p class="mt-2">{{ __('ui.providers.bulk_confirm_hint') }}</p>
                </x-ui.alert>
            @endif
            <x-slot:footer>
                @if ($bulkPreview === null)
                    <x-ui.button variant="secondary" wire:click="closeDialog">{{ __('ui.common.cancel') }}</x-ui.button>
                    <x-ui.button wire:click="previewBulk" wire:loading.attr="disabled">{{ __('ui.providers.bulk_review') }}</x-ui.button>
                @else
                    <x-ui.button variant="secondary" wire:click="backToBulkForm">{{ __('ui.common.back') }}</x-ui.button>
                    <x-ui.button wire:click="applyBulk" wire:loading.attr="disabled" data-autofocus>{{ __('ui.providers.bulk_apply', ['count' => $bulkPreview['count']]) }}</x-ui.button>
                @endif
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <x-ui.toast :notice="$notice" />
</div>
