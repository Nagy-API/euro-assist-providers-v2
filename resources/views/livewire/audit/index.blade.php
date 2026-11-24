<div>
    <x-ui.page-header :title="__('ui.nav.audit')" :subtitle="__('ui.audit.subtitle')" />

    <form wire:submit.prevent class="mb-4 ea-card p-5" role="search" aria-label="{{ __('ui.providers.filters') }}">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <x-ui.field name="action" id="aud-action" type="select" :label="__('ui.audit.action')" wire:model.live="action">
                <option value="">{{ __('ui.common.all') }}</option>
                @foreach ($actions as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach
            </x-ui.field>
            <x-ui.field name="actor" id="aud-actor" type="select" :label="__('ui.audit.actor')" wire:model.live="actor">
                <option value="">{{ __('ui.common.all') }}</option>
                @foreach ($actors as $u)<option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>@endforeach
            </x-ui.field>
            <x-ui.field name="result" id="aud-result" type="select" :label="__('ui.audit.result')" wire:model.live="result">
                <option value="">{{ __('ui.common.all') }}</option>
                @foreach ($results as $r)<option value="{{ $r }}">{{ __('ui.audit.results.'.$r) }}</option>@endforeach
            </x-ui.field>
            <x-ui.field name="from" id="aud-from" type="date" :label="__('ui.audit.from')" wire:model.live="from" dir="ltr" />
            <x-ui.field name="to" id="aud-to" type="date" :label="__('ui.audit.to')" wire:model.live="to" dir="ltr" />
        </div>
        @if ($hasFilters)
            <div class="mt-3"><x-ui.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">{{ __('ui.common.clear_filters') }}</x-ui.button></div>
        @endif
    </form>

    <div wire:loading.delay.short class="mb-2"><x-ui.loading /></div>

    @if ($events->isEmpty())
        <div class="ea-card"><x-ui.empty-state :title="__('ui.audit.none')" icon="clipboard">{{ $hasFilters ? __('ui.providers.no_results_hint') : '' }}</x-ui.empty-state></div>
    @else
        <x-ui.table :caption="__('ui.nav.audit')">
            <thead>
                <tr>
                    <x-ui.th>{{ __('ui.audit.when') }}</x-ui.th>
                    <x-ui.th>{{ __('ui.audit.actor') }}</x-ui.th>
                    <x-ui.th>{{ __('ui.audit.action') }}</x-ui.th>
                    <x-ui.th class="hidden md:table-cell">{{ __('ui.audit.target') }}</x-ui.th>
                    <x-ui.th>{{ __('ui.audit.result') }}</x-ui.th>
                    <x-ui.th class="hidden lg:table-cell">{{ __('ui.audit.details') }}</x-ui.th>
                    <x-ui.th class="hidden xl:table-cell">IP</x-ui.th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($events as $event)
                    <tr wire:key="audit-{{ $event->id }}" class="align-top">
                        <td class="px-3 py-2.5 whitespace-nowrap"><span class="ltr-text">{{ $event->created_at?->format('Y-m-d H:i:s') }}</span></td>
                        <td class="px-3 py-2.5">
                            @if ($event->actor)
                                <span class="font-medium" dir="auto"><bdi>{{ $event->actor->name }}</bdi></span>
                                <span class="block text-xs text-slate-600"><bdi dir="ltr">{{ $event->actor->email }}</bdi></span>
                            @else
                                <span class="text-slate-600">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-2.5"><span class="ltr-text font-mono text-xs">{{ $event->action }}</span></td>
                        <td class="hidden px-3 py-2.5 md:table-cell"><span class="ltr-text text-xs">{{ $event->target_type ? class_basename($event->target_type) : '—' }}@if ($event->target_id) #{{ $event->target_id }}@endif</span></td>
                        <td class="px-3 py-2.5">
                            <x-ui.badge :tone="match ($event->result) { 'success' => 'green', 'denied' => 'red', default => 'amber' }">
                                {{ in_array($event->result, $results, true) ? __('ui.audit.results.'.$event->result) : $event->result }}
                            </x-ui.badge>
                        </td>
                        <td class="hidden max-w-xs px-3 py-2.5 lg:table-cell"><span class="ltr-text break-all font-mono text-xs text-slate-700">{{ $event->metadata ? \Illuminate\Support\Str::limit(json_encode($event->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 160) : '—' }}</span></td>
                        <td class="hidden px-3 py-2.5 xl:table-cell"><span class="ltr-text text-xs">{{ $event->ip_address ?? '—' }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
        <div class="mt-4">{{ $events->links() }}</div>
    @endif
</div>
