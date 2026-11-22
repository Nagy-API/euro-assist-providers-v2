@use('App\Models\ProviderTranslation')
@use('App\Services\Ui\SafeUrl')
@php
    $display = $provider->displayTranslation($locale);
    $mapLink = SafeUrl::http($provider->map_url);
    $siteLink = SafeUrl::website($provider->website);
    $hasCoords = $provider->latitude !== null && $provider->longitude !== null;
@endphp
<div>
    <nav aria-label="{{ __('ui.common.breadcrumb') }}" class="mb-3 text-sm">
        <a href="{{ route('providers.index') }}" class="inline-flex min-h-8 items-center gap-1 font-medium text-teal-800 underline underline-offset-2"><x-ui.icon name="chevron-left" flip class="size-4" />{{ __('ui.providers.title') }}</a>
    </nav>

    <x-ui.page-header :title="$display?->name ?? __('ui.providers.details')">
        <x-slot:actions>
            @can('update', $provider)
                @unless ($provider->trashed())
                    <x-ui.button :href="route('providers.edit', $provider)" icon="pencil">{{ __('ui.common.edit') }}</x-ui.button>
                @endunless
            @endcan
            @if ($provider->trashed())
                @can('restore', $provider)
                    <x-ui.button variant="secondary" icon="restore" wire:click="confirmRestore">{{ __('ui.common.restore') }}</x-ui.button>
                @endcan
            @else
                @can('delete', $provider)
                    <x-ui.button variant="danger-outline" icon="trash" wire:click="confirmDelete">{{ __('ui.common.delete') }}</x-ui.button>
                @endcan
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-6 flex flex-wrap items-center gap-2">
        <x-ui.status-badge :active="$provider->is_active" :deleted="$provider->trashed()" />
        <x-ui.badge tone="slate" dir="auto">{{ $provider->type?->label($locale) ?? '—' }}</x-ui.badge>
        <x-ui.translation-badges :provider="$provider" />
    </div>

    @if ($provider->trashed())
        <x-ui.alert tone="warning" class="mb-6">{{ __('ui.providers.is_deleted_notice', ['date' => $provider->deleted_at?->format('Y-m-d H:i')]) }}</x-ui.alert>
    @endif

    {{-- Translations side by side: only what is stored is shown; a missing language is never invented. --}}
    <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        @foreach ($locales as $loc)
            @php $t = $provider->translation($loc); @endphp
            <section class="rounded-lg border border-slate-200 bg-white" aria-labelledby="tr-{{ $loc }}" lang="{{ $loc }}" dir="{{ in_array($loc, config('providers.rtl_locales'), true) ? 'rtl' : 'ltr' }}">
                <h2 id="tr-{{ $loc }}" class="border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-900">{{ __('ui.locale.'.$loc) }}</h2>
                @if ($t === null)
                    <p class="px-4 py-6 text-sm text-slate-600">{{ __('ui.translation.no_translation') }}</p>
                @else
                    <dl class="divide-y divide-slate-100 text-sm">
                        @foreach (ProviderTranslation::TEXT_FIELDS as $field)
                            <div class="grid grid-cols-3 gap-3 px-4 py-2.5">
                                <dt class="text-slate-600">{{ __('ui.providers.fields.'.$field) }}</dt>
                                <dd class="col-span-2 break-words whitespace-pre-line text-slate-900">{{ filled($t->{$field}) ? $t->{$field} : '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </section>
        @endforeach
    </div>

    <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="rounded-lg border border-slate-200 bg-white" aria-labelledby="contact-h">
            <h2 id="contact-h" class="border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-900">{{ __('ui.providers.contact') }}</h2>
            <dl class="divide-y divide-slate-100 text-sm">
                <div class="grid grid-cols-3 gap-3 px-4 py-2.5">
                    <dt class="text-slate-600">{{ __('ui.providers.phone') }}</dt>
                    <dd class="col-span-2">@if (filled($provider->phone))<span class="ltr-text break-all">{{ $provider->phone }}</span>@else — @endif</dd>
                </div>
                <div class="grid grid-cols-3 gap-3 px-4 py-2.5">
                    <dt class="text-slate-600">{{ __('ui.providers.email') }}</dt>
                    <dd class="col-span-2">
                        @if (filled($provider->email))
                            @if (filter_var($provider->email, FILTER_VALIDATE_EMAIL))
                                <a class="ltr-text break-all text-teal-900 underline underline-offset-2" href="mailto:{{ $provider->email }}">{{ $provider->email }}</a>
                            @else
                                <span class="ltr-text break-all">{{ $provider->email }}</span>
                            @endif
                        @else — @endif
                    </dd>
                </div>
                <div class="grid grid-cols-3 gap-3 px-4 py-2.5">
                    <dt class="text-slate-600">{{ __('ui.providers.website') }}</dt>
                    <dd class="col-span-2">
                        @if ($siteLink)
                            <a class="ltr-text break-all text-teal-900 underline underline-offset-2" href="{{ $siteLink }}" target="_blank" rel="noopener noreferrer">{{ $provider->website }}</a>
                        @elseif (filled($provider->website))
                            <span class="ltr-text break-all">{{ $provider->website }}</span>
                        @else — @endif
                    </dd>
                </div>
                <div class="grid grid-cols-3 gap-3 px-4 py-2.5">
                    <dt class="text-slate-600">{{ __('ui.providers.map_url') }}</dt>
                    <dd class="col-span-2">
                        @if ($mapLink)
                            <a class="inline-flex items-center gap-1 text-teal-900 underline underline-offset-2" href="{{ $mapLink }}" target="_blank" rel="noopener noreferrer">{{ __('ui.providers.open_map') }}<span class="sr-only"> ({{ __('ui.common.new_tab') }})</span></a>
                        @elseif (filled($provider->map_url))
                            <span class="ltr-text break-all text-slate-700">{{ $provider->map_url }}</span>
                            <span class="block text-xs text-slate-600">{{ __('ui.providers.map_not_linked') }}</span>
                        @else — @endif
                    </dd>
                </div>
                <div class="grid grid-cols-3 gap-3 px-4 py-2.5">
                    <dt class="text-slate-600">{{ __('ui.providers.coordinates') }}</dt>
                    <dd class="col-span-2">@if ($hasCoords)<span class="ltr-text">{{ $provider->latitude }}, {{ $provider->longitude }}</span>@else — @endif</dd>
                </div>
            </dl>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white" aria-labelledby="meta-h">
            <h2 id="meta-h" class="border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-900">{{ __('ui.providers.record') }}</h2>
            <dl class="divide-y divide-slate-100 text-sm">
                <div class="grid grid-cols-3 gap-3 px-4 py-2.5"><dt class="text-slate-600">ID</dt><dd class="col-span-2"><span class="ltr-text">{{ $provider->id }}</span></dd></div>
                <div class="grid grid-cols-3 gap-3 px-4 py-2.5"><dt class="text-slate-600">{{ __('ui.providers.created') }}</dt><dd class="col-span-2"><span class="ltr-text">{{ $provider->created_at?->format('Y-m-d H:i') }}</span> · <bdi>{{ $createdBy ?? '—' }}</bdi></dd></div>
                <div class="grid grid-cols-3 gap-3 px-4 py-2.5"><dt class="text-slate-600">{{ __('ui.providers.updated_at') }}</dt><dd class="col-span-2"><span class="ltr-text">{{ $provider->updated_at?->format('Y-m-d H:i') }}</span> · <bdi>{{ $updatedBy ?? '—' }}</bdi></dd></div>
                <div class="grid grid-cols-3 gap-3 px-4 py-2.5"><dt class="text-slate-600">{{ __('ui.providers.revision') }}</dt><dd class="col-span-2"><span class="ltr-text">{{ $provider->revision }}</span></dd></div>
            </dl>
        </section>
    </div>

    @can('audit.view')
        <section class="rounded-lg border border-slate-200 bg-white" aria-labelledby="audit-h">
            <h2 id="audit-h" class="border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-900">{{ __('ui.providers.audit_snippet') }}</h2>
            @if ($audit->isEmpty())
                <p class="px-4 py-6 text-sm text-slate-600">{{ __('ui.audit.none') }}</p>
            @else
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach ($audit as $event)
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5">
                            <span class="ltr-text text-slate-700">{{ $event->created_at?->format('Y-m-d H:i') }}</span>
                            <span class="ltr-text font-medium">{{ $event->action }}</span>
                            <span class="text-slate-700"><bdi>{{ $event->actor?->name ?? '—' }}</bdi></span>
                        </li>
                    @endforeach
                </ul>
                @can('audit.view')
                    <p class="border-t border-slate-100 px-4 py-2 text-sm"><a class="text-teal-900 underline underline-offset-2" href="{{ route('audit.index') }}">{{ __('ui.audit.open_log') }}</a></p>
                @endcan
            @endif
        </section>
    @endcan

    @if ($dialog === 'delete' || $dialog === 'restore')
        <x-ui.modal :title="$dialog === 'delete' ? __('ui.providers.delete_title') : __('ui.providers.restore_title')" close="closeDialog" size="sm">
            <p class="text-sm text-slate-700">{{ $dialog === 'delete' ? __('ui.providers.delete_body') : __('ui.providers.restore_body') }}</p>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="closeDialog">{{ __('ui.common.cancel') }}</x-ui.button>
                @if ($dialog === 'delete')
                    <x-ui.button variant="danger" wire:click="performDelete" data-autofocus>{{ __('ui.common.delete') }}</x-ui.button>
                @else
                    <x-ui.button wire:click="performRestore" data-autofocus>{{ __('ui.common.restore') }}</x-ui.button>
                @endif
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <x-ui.toast :notice="$notice" />
</div>
