<div>
    <x-ui.page-header :title="__('migration.title')" :subtitle="__('migration.subtitle')" />

    <x-ui.alert tone="info" class="mb-4">{{ __('migration.privacy_notice') }}</x-ui.alert>

    <div class="mb-4 flex flex-wrap items-center gap-2" role="tablist" aria-label="{{ __('migration.filter_status') }}">
        @foreach (\App\Livewire\Migration\ReviewIndex::STATUSES as $s)
            <button type="button" role="tab" aria-selected="{{ $status === $s ? 'true' : 'false' }}" wire:click="$set('status', '{{ $s }}')" wire:key="tab-{{ $s }}"
                    class="inline-flex min-h-10 items-center gap-2 rounded-md border px-3 text-sm font-medium {{ $status === $s ? 'border-teal-700 bg-teal-700 text-white' : 'border-slate-300 bg-white text-slate-800 hover:bg-slate-50' }}">
                {{ __('migration.statuses.'.$s) }}
                <span class="rounded-full bg-black/10 px-2 text-xs">{{ $counts[$s] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    <div wire:loading.delay.short class="mb-2 text-sm text-slate-600">{{ __('migration.loading') }}</div>

    @if ($suggestions->isEmpty())
        <div class="rounded-lg border border-slate-200 bg-white"><x-ui.empty-state :title="__('migration.empty')" icon="clipboard" /></div>
    @else
        <ul class="space-y-4">
            @foreach ($suggestions as $suggestion)
                @php
                    $evidence = (array) ($suggestion->right?->review_evidence ?? []);
                    $discarded = (array) ($evidence['discarded_ar_values'] ?? []);
                @endphp
                <li wire:key="suggestion-{{ $suggestion->id }}" class="rounded-lg border border-slate-200 bg-white p-4" data-suggestion-id="{{ $suggestion->id }}">
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold text-slate-900">{{ __('migration.suggestion') }} #{{ $suggestion->id }}</span>
                        @foreach ((array) $suggestion->signals as $signal)
                            <x-ui.badge tone="teal">{{ __('migration.signals.'.$signal) }}</x-ui.badge>
                        @endforeach
                        <x-ui.badge tone="outline">{{ trans_choice('migration.signal_count', $suggestion->signal_count, ['count' => $suggestion->signal_count]) }}</x-ui.badge>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        @foreach (['left' => 'en', 'right' => 'ar'] as $side => $sideLocale)
                            @php
                                $map = $suggestion->{$side};
                                $provider = $map?->provider;
                                $t = $provider?->translation($sideLocale);
                            @endphp
                            <section class="rounded-md border border-slate-200 p-3" aria-label="{{ __('migration.side.'.$sideLocale) }}">
                                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-600">{{ __('migration.side.'.$sideLocale) }}</h3>
                                @if ($t)
                                    <dl class="space-y-1 text-sm">
                                        <div><dt class="inline font-medium">{{ __('migration.fields.name') }}:</dt> <dd class="inline" dir="auto"><bdi>{{ $t->name }}</bdi></dd></div>
                                        <div><dt class="inline font-medium">{{ __('migration.fields.address') }}:</dt> <dd class="inline" dir="auto"><bdi>{{ $t->address }}</bdi></dd></div>
                                        <div><dt class="inline font-medium">{{ __('migration.fields.city') }}:</dt> <dd class="inline" dir="auto"><bdi>{{ $t->city ?? '—' }}</bdi></dd></div>
                                        <div><dt class="inline font-medium">{{ __('migration.fields.district') }}:</dt> <dd class="inline" dir="auto"><bdi>{{ $t->district ?? '—' }}</bdi></dd></div>
                                        <div><dt class="inline font-medium">{{ __('migration.fields.type') }}:</dt> <dd class="inline"><bdi>{{ $provider->type?->label($locale) ?? '—' }}</bdi></dd></div>
                                        <div><dt class="inline font-medium">{{ __('migration.fields.phone') }}:</dt> <dd class="inline" dir="ltr"><bdi>{{ $provider->phone ?? '—' }}</bdi></dd></div>
                                        <div class="break-all"><dt class="inline font-medium">{{ __('migration.fields.map_url') }}:</dt> <dd class="inline" dir="ltr"><bdi>{{ $provider->map_url ?? '—' }}</bdi></dd></div>
                                        <div class="text-xs text-slate-600">{{ __('migration.fields.source') }}: <span dir="ltr">{{ $map->source_table }} #{{ $map->source_id }}</span></div>
                                    </dl>
                                @else
                                    <p class="text-sm text-slate-600">{{ __('migration.missing_side') }}</p>
                                @endif
                            </section>
                        @endforeach
                    </div>

                    @if ($suggestion->status === 'approved' && $discarded !== [])
                        <div class="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950">
                            <p class="font-semibold">{{ __('migration.conflict_title') }}</p>
                            <ul class="mt-1 list-disc ps-5">
                                @foreach ($discarded as $field => $value)
                                    <li><span class="font-medium">{{ $field }}:</span> <bdi dir="ltr">{{ is_scalar($value) ? $value : '' }}</bdi></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="mt-3 flex flex-wrap gap-2">
                        @if ($suggestion->status === 'suggested')
                            <x-ui.button size="sm" wire:click="approve({{ $suggestion->id }})" wire:confirm="{{ __('migration.confirm_approve') }}">{{ __('migration.actions.approve') }}</x-ui.button>
                            <x-ui.button size="sm" variant="secondary" wire:click="reject({{ $suggestion->id }})">{{ __('migration.actions.reject') }}</x-ui.button>
                        @elseif ($suggestion->status === 'approved')
                            <x-ui.button size="sm" variant="danger-outline" wire:click="undo({{ $suggestion->id }})" wire:confirm="{{ __('migration.confirm_undo') }}">{{ __('migration.actions.undo') }}</x-ui.button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $suggestions->links() }}</div>
    @endif

    <x-ui.toast :notice="$notice" />
</div>
