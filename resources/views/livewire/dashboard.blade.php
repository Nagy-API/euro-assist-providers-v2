@php
    $activeShare = $counters['total'] > 0 ? (int) round($counters['active'] / $counters['total'] * 100) : 0;
    $ring = 2 * M_PI * 52;
    $figures = [
        ['key' => 'total', 'value' => $counters['total'], 'tone' => 'text-slate-900', 'bar' => null, 'href' => route('providers.index')],
        ['key' => 'active', 'value' => $counters['active'], 'tone' => 'text-green-700', 'bar' => 'bg-green-600', 'href' => route('providers.index', ['status' => 'active'])],
        ['key' => 'inactive', 'value' => $counters['inactive'], 'tone' => 'text-amber-700', 'bar' => 'bg-amber-500', 'href' => route('providers.index', ['status' => 'inactive'])],
    ];
    if ($counters['deleted'] !== null) {
        $figures[] = ['key' => 'deleted', 'value' => $counters['deleted'], 'tone' => 'text-red-700', 'bar' => 'bg-red-600', 'href' => Gate::allows('providers.restore') ? route('providers.index', ['trashed' => 'only']) : null];
    }
    $t = $counters['translations'];
@endphp
<div class="space-y-8">
    {{-- Hero band: Euro Assist gradient with greeting and quick actions (Cards System dashboard). --}}
    <section class="ea-hero animate-enter rounded-3xl px-6 pt-7 pb-24 sm:px-8 lg:pb-28" aria-labelledby="dash-title">
        <div class="relative z-10 flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div class="min-w-0">
                <p class="text-xs font-extrabold tracking-[0.14em] text-white/85 uppercase">{{ now()->locale($locale)->translatedFormat('l, j F Y') }}</p>
                <h1 id="dash-title" class="mt-2 text-3xl leading-tight font-extrabold tracking-tight sm:text-4xl">{{ __('ui.dashboard.greeting', ['name' => $user?->name]) }}</h1>
                <p class="mt-1 max-w-2xl text-base text-white/90">{{ __('ui.dashboard.hero_subtitle') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('providers.create')
                    <a href="{{ route('providers.create') }}" class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-white px-4 text-sm font-bold text-brand-700 shadow-md transition hover:-translate-y-px hover:shadow-lg"><x-ui.icon name="plus" class="size-4" />{{ __('ui.nav.add_provider') }}</a>
                @endcan
                @can('imports.run')
                    <a href="{{ route('imports.index') }}" class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-white/40 bg-white/10 px-4 text-sm font-bold text-white transition hover:bg-white/20"><x-ui.icon name="upload" class="size-4" />{{ __('ui.nav.import') }}</a>
                @endcan
                @can('exports.run')
                    <a href="{{ route('exports.index') }}" class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-white/40 bg-white/10 px-4 text-sm font-bold text-white transition hover:bg-white/20"><x-ui.icon name="download" class="size-4" />{{ __('ui.nav.export') }}</a>
                @endcan
            </div>
        </div>
    </section>

    {{-- Overview: ring + linked figures, overlapping the hero. --}}
    <section aria-labelledby="records-heading" class="relative z-10 -mt-24 mx-0 animate-enter sm:mx-4 lg:-mt-28 lg:mx-6">
        <div class="ea-card flex flex-col gap-5 rounded-3xl p-5 shadow-lg sm:p-6 lg:flex-row lg:items-center">
            <h2 id="records-heading" class="sr-only">{{ __('ui.dashboard.records_heading') }}</h2>
            <div class="relative hidden size-32 shrink-0 lg:block" aria-hidden="true">
                <svg viewBox="0 0 120 120" class="size-32 -rotate-90">
                    <circle cx="60" cy="60" r="52" fill="none" stroke="var(--color-slate-100)" stroke-width="12" />
                    <circle cx="60" cy="60" r="52" fill="none" stroke="var(--color-brand-600)" stroke-width="12" stroke-linecap="round"
                            stroke-dasharray="{{ $ring }}" stroke-dashoffset="{{ $ring * (1 - $activeShare / 100) }}" />
                </svg>
                <div class="absolute inset-0 flex flex-col items-center justify-center leading-tight">
                    <span class="ltr-text text-2xl font-extrabold">{{ $activeShare }}%</span>
                    <span class="text-xs font-semibold text-slate-500">{{ __('ui.dashboard.active_share') }}</span>
                </div>
            </div>
            <dl class="grid min-w-0 flex-1 grid-cols-2 gap-px overflow-hidden rounded-2xl bg-slate-200 {{ count($figures) === 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3' }}">
                @foreach ($figures as $fig)
                    <div class="flex flex-col gap-1 bg-white p-4 lg:px-5">
                        <dt class="flex items-center gap-1 text-xs font-bold tracking-wide text-slate-600 uppercase">
                            {{ __('ui.dashboard.'.$fig['key']) }}
                            <x-ui.help :text="__('ui.dashboard.help.'.$fig['key'])" />
                        </dt>
                        <dd class="text-3xl font-extrabold tracking-tight sm:text-4xl {{ $fig['tone'] }}"><span class="ltr-text">{{ number_format($fig['value']) }}</span></dd>
                        @if ($fig['bar'] && $counters['total'] > 0)
                            <div class="h-1.5 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><div class="h-full rounded-full {{ $fig['bar'] }}" style="width: {{ min(100, round($fig['value'] / max(1, $counters['total']) * 100)) }}%"></div></div>
                        @endif
                        @if ($fig['href'])
                            <dd><a href="{{ $fig['href'] }}" class="group inline-flex items-center gap-1 text-sm font-semibold text-brand-700 hover:text-brand-800">{{ __('ui.dashboard.view_list') }}<x-ui.icon name="chevron-right" class="size-3.5 transition-transform group-hover:translate-x-0.5 rtl:group-hover:-translate-x-0.5" flip /></a></dd>
                        @endif
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    @if (! $canEdit)
        <x-ui.alert tone="info">{{ __('ui.dashboard.read_only') }}</x-ui.alert>
    @endif

    {{-- What the numbers mean: canonical records are not unique branches while bilingual pairing is open. --}}
    <section aria-labelledby="meaning-heading" class="ea-card animate-enter p-5 sm:p-6">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <h2 id="meaning-heading" class="flex items-center gap-2 text-base font-extrabold text-slate-900">
                <span class="inline-flex size-8 items-center justify-center rounded-lg bg-sky-50 text-sky-700"><x-ui.icon name="info" class="size-5" /></span>
                {{ __('ui.dashboard.meaning.title') }}
                <x-ui.help :text="__('ui.dashboard.help.records')" />
            </h2>
        </div>
        <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['records', $counters['total'] + ($counters['deleted'] ?? 0), 'building'],
                ['source_rows', $provenance['source_rows'], 'server'],
                ['unpaired', $provenance['unpaired'], 'arrows'],
                ['reviewed', $provenance['reviewed_pairs'], 'check-circle'],
            ] as [$key, $value, $icon])
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <dt class="flex items-center gap-2 text-sm font-bold text-slate-700"><x-ui.icon :name="$icon" class="size-4 text-slate-500" />{{ __('ui.dashboard.meaning.'.$key) }}</dt>
                    <dd class="mt-1 text-2xl font-extrabold text-slate-900"><span class="ltr-text">{{ number_format($value) }}</span></dd>
                    <dd class="mt-1 text-xs leading-relaxed text-slate-600">{{ __('ui.dashboard.meaning.'.$key.'_hint') }}</dd>
                </div>
            @endforeach
        </dl>
        @if ($provenance['unpaired'] > 0)
            <p class="mt-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">{{ __('ui.dashboard.meaning.note', ['count' => number_format($provenance['unpaired'])]) }}</p>
        @endif
    </section>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
        <section aria-labelledby="types-heading" class="ea-card animate-enter p-5 sm:p-6">
            <h2 id="types-heading" class="mb-4 flex items-center gap-1 text-base font-extrabold text-slate-900">
                {{ __('ui.dashboard.per_type') }}
                <x-ui.help :text="__('ui.dashboard.help.per_type')" />
            </h2>
            @if ($perType['types'] === [])
                <x-ui.empty-state :title="__('ui.dashboard.no_data')" icon="chart" />
            @else
                @php $max = max(1, collect($perType['types'])->max('count'), $perType['other']); @endphp
                <ul class="space-y-3.5">
                    @foreach ($perType['types'] as $row)
                        <li>
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <a class="min-w-0 truncate font-semibold text-slate-800 hover:text-brand-700" href="{{ route('providers.index', ['type' => $row['id']]) }}" dir="auto">{{ $row['label'] }}</a>
                                <span class="ltr-text shrink-0 font-bold text-slate-900">{{ number_format($row['count']) }}</span>
                            </div>
                            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><div class="h-full rounded-full bg-[image:var(--ea-hero)]" style="width: {{ round($row['count'] / $max * 100) }}%"></div></div>
                        </li>
                    @endforeach
                    @if ($perType['other'] > 0)
                        <li>
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <span class="font-semibold text-slate-600">{{ __('ui.dashboard.other_types', ['count' => $perType['other_types']]) }}</span>
                                <span class="ltr-text shrink-0 font-bold text-slate-900">{{ number_format($perType['other']) }}</span>
                            </div>
                            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><div class="h-full rounded-full bg-slate-400" style="width: {{ round($perType['other'] / $max * 100) }}%"></div></div>
                        </li>
                    @endif
                </ul>
            @endif
        </section>

        <section aria-labelledby="coverage-heading" class="ea-card animate-enter p-5 sm:p-6">
            <h2 id="coverage-heading" class="mb-4 flex items-center gap-1 text-base font-extrabold text-slate-900">
                {{ __('ui.dashboard.coverage') }}
                <x-ui.help :text="__('ui.dashboard.help.coverage')" />
            </h2>
            @php $covTotal = max(1, $t['both'] + $t['only_ar'] + $t['only_en'] + $t['none']); @endphp
            <div class="flex h-3 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                <div class="bg-green-600" style="width: {{ $t['both'] / $covTotal * 100 }}%"></div>
                <div class="bg-brand-500" style="width: {{ $t['only_ar'] / $covTotal * 100 }}%"></div>
                <div class="bg-sky-600" style="width: {{ $t['only_en'] / $covTotal * 100 }}%"></div>
                <div class="bg-amber-500" style="width: {{ $t['none'] / $covTotal * 100 }}%"></div>
            </div>
            <dl class="mt-4 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                @foreach ([['both', 'bg-green-600'], ['only_ar', 'bg-brand-500'], ['only_en', 'bg-sky-600'], ['none', 'bg-amber-500']] as [$key, $dot])
                    @continue($key === 'none' && $t['none'] === 0)
                    <div class="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2">
                        <dt class="flex items-center gap-2 text-slate-700"><span class="size-2.5 rounded-full {{ $dot }}" aria-hidden="true"></span>{{ __('ui.dashboard.cov_'.$key) }}</dt>
                        <dd class="font-bold text-slate-900"><span class="ltr-text">{{ number_format($t[$key]) }}</span></dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-3 text-xs text-slate-600">{{ __('ui.dashboard.cov_ar') }}: <span class="ltr-text font-semibold">{{ number_format($t['ar']) }}</span> · {{ __('ui.dashboard.cov_en') }}: <span class="ltr-text font-semibold">{{ number_format($t['en']) }}</span></p>

            <h3 class="mt-6 mb-2 flex items-center gap-1 text-sm font-extrabold text-slate-900">
                {{ __('ui.dashboard.quality') }}
                <x-ui.help :text="__('ui.dashboard.help.quality')" />
            </h3>
            <dl class="grid grid-cols-2 gap-2 text-sm">
                <div class="rounded-lg bg-slate-50 px-3 py-2">
                    <dt class="text-slate-700">{{ __('ui.dashboard.missing_city') }}</dt>
                    <dd class="text-xl font-extrabold text-slate-900"><span class="ltr-text">{{ number_format($counters['missing_city']) }}</span></dd>
                </div>
                <div class="rounded-lg bg-slate-50 px-3 py-2">
                    <dt class="text-slate-700">{{ __('ui.dashboard.missing_phone') }}</dt>
                    <dd class="text-xl font-extrabold text-slate-900"><span class="ltr-text">{{ number_format($counters['missing_phone']) }}</span></dd>
                </div>
                <div class="col-span-2 rounded-lg bg-slate-50 px-3 py-2">
                    <dt class="flex items-center gap-1 text-slate-700">{{ __('ui.dashboard.featured') }} <x-ui.help :text="__('ui.dashboard.help.featured')" /></dt>
                    <dd class="text-xl font-extrabold text-slate-900">
                        <a href="{{ route('featured.index') }}" class="hover:text-brand-700"><span class="ltr-text">{{ number_format($counters['featured']['total']) }}</span></a>
                        <span class="text-sm font-normal text-slate-600">{{ __('ui.dashboard.featured_active', ['count' => $counters['featured']['active']]) }}</span>
                    </dd>
                </div>
            </dl>
        </section>
    </div>

    @if ($legacy !== null)
        <section aria-labelledby="legacy-heading" class="animate-enter rounded-2xl border border-amber-300 bg-amber-50 p-5 sm:p-6">
            <h2 id="legacy-heading" class="flex items-center gap-2 text-base font-extrabold text-amber-950"><x-ui.icon name="exclamation" class="size-5 text-amber-700" />{{ __('ui.dashboard.legacy.title') }}</h2>
            <p class="mt-1 text-sm text-amber-950">{{ __('ui.dashboard.legacy.intro') }}</p>
            <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach (['unpaired', 'suggestions', 'held', 'provisional'] as $key)
                    <div class="rounded-xl bg-white p-3 shadow-xs">
                        <dt class="text-sm text-slate-700">{{ __('ui.dashboard.legacy.'.$key) }}</dt>
                        <dd class="mt-1 text-xl font-extrabold text-slate-900"><span class="ltr-text">{{ number_format($legacy[$key]) }}</span></dd>
                    </div>
                @endforeach
            </dl>
            @if ($reviewRoute)
                <a href="{{ route('migration.review.index') }}" class="mt-4 inline-flex items-center gap-1 text-sm font-bold text-brand-700 hover:text-brand-800">{{ __('ui.dashboard.legacy.open_review') }}<x-ui.icon name="chevron-right" class="size-4" flip /></a>
            @else
                <p class="mt-4 text-sm text-amber-950">{{ __('ui.dashboard.legacy.review_local_only') }}</p>
            @endif
        </section>
    @endif

    <section aria-labelledby="recent-heading" class="animate-enter">
        <h2 id="recent-heading" class="mb-3 flex items-center gap-1 text-base font-extrabold text-slate-900">
            {{ __('ui.dashboard.recent') }}
            <x-ui.help :text="__('ui.dashboard.help.recent')" />
        </h2>
        @if ($recent->isEmpty())
            <div class="ea-card"><x-ui.empty-state :title="__('ui.dashboard.no_data')" icon="building" /></div>
        @else
            <x-ui.table :caption="__('ui.dashboard.recent')">
                <thead>
                    <tr>
                        <x-ui.th>{{ __('ui.providers.name') }}</x-ui.th>
                        <x-ui.th class="hidden sm:table-cell">{{ __('ui.providers.type') }}</x-ui.th>
                        <x-ui.th>{{ __('ui.providers.updated_at') }}</x-ui.th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($recent as $provider)
                        @php $tr = $provider->displayTranslation($locale); @endphp
                        <tr class="transition-colors hover:bg-slate-50">
                            <td class="px-3 py-3">
                                <a href="{{ route('providers.show', $provider) }}" class="font-semibold text-slate-900 hover:text-brand-700" dir="auto">{{ $tr?->name ?? '—' }}</a>
                            </td>
                            <td class="hidden px-3 py-3 text-slate-700 sm:table-cell" dir="auto">{{ $provider->type?->label($locale) ?? '—' }}</td>
                            <td class="px-3 py-3 whitespace-nowrap text-slate-600"><span class="ltr-text">{{ $provider->updated_at?->format('Y-m-d H:i') }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </section>
</div>
