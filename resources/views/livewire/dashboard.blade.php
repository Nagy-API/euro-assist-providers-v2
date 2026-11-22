<div>
    <x-ui.page-header :title="__('ui.dashboard.title')" :subtitle="__('ui.dashboard.subtitle')">
        @can('providers.create')
            <x-slot:actions>
                <x-ui.button :href="route('providers.create')" icon="plus">{{ __('ui.nav.add_provider') }}</x-ui.button>
            </x-slot:actions>
        @endcan
    </x-ui.page-header>

    @if (! $canEdit)
        <x-ui.alert tone="info" class="mb-6">{{ __('ui.dashboard.read_only') }}</x-ui.alert>
    @endif

    <section aria-labelledby="records-heading" class="mb-8">
        <h2 id="records-heading" class="mb-3 text-base font-semibold text-slate-900">
            {{ __('ui.dashboard.records_heading') }}
            <x-ui.help :text="__('ui.dashboard.help.records')" />
        </h2>
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @php
                $cards = [
                    ['key' => 'total', 'value' => $counters['total'], 'help' => 'total', 'href' => route('providers.index')],
                    ['key' => 'active', 'value' => $counters['active'], 'help' => 'active', 'href' => route('providers.index', ['status' => 'active'])],
                    ['key' => 'inactive', 'value' => $counters['inactive'], 'help' => 'inactive', 'href' => route('providers.index', ['status' => 'inactive'])],
                ];
                if ($counters['deleted'] !== null) {
                    $cards[] = ['key' => 'deleted', 'value' => $counters['deleted'], 'help' => 'deleted', 'href' => Gate::allows('providers.restore') ? route('providers.index', ['trashed' => 'only']) : null];
                }
            @endphp
            @foreach ($cards as $card)
                <div class="rounded-lg border border-slate-200 bg-white p-4">
                    <dt class="flex items-center gap-1 text-sm font-medium text-slate-700">
                        {{ __('ui.dashboard.'.$card['key']) }}
                        <x-ui.help :text="__('ui.dashboard.help.'.$card['help'])" />
                    </dt>
                    <dd class="mt-2 text-3xl font-semibold text-slate-900"><span class="ltr-text">{{ number_format($card['value']) }}</span></dd>
                    @if ($card['href'])
                        <a href="{{ $card['href'] }}" class="mt-2 inline-block text-sm font-medium text-teal-800 underline underline-offset-2">{{ __('ui.dashboard.view_list') }}</a>
                    @endif
                </div>
            @endforeach
        </dl>
    </section>

    <div class="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <section aria-labelledby="types-heading" class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 id="types-heading" class="mb-3 text-base font-semibold text-slate-900">
                {{ __('ui.dashboard.per_type') }}
                <x-ui.help :text="__('ui.dashboard.help.per_type')" />
            </h2>
            @if ($perType['types'] === [])
                <x-ui.empty-state :title="__('ui.dashboard.no_data')" icon="chart" />
            @else
                @php $max = max(1, collect($perType['types'])->max('count'), $perType['other']); @endphp
                <ul class="space-y-3">
                    @foreach ($perType['types'] as $row)
                        <li>
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <a class="min-w-0 truncate font-medium text-slate-900 hover:underline" href="{{ route('providers.index', ['type' => $row['id']]) }}" dir="auto">{{ $row['label'] }}</a>
                                <span class="ltr-text shrink-0 font-semibold text-slate-900">{{ number_format($row['count']) }}</span>
                            </div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><div class="h-full rounded-full bg-teal-700" style="width: {{ round($row['count'] / $max * 100) }}%"></div></div>
                        </li>
                    @endforeach
                    @if ($perType['other'] > 0)
                        <li>
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <span class="font-medium text-slate-700">{{ __('ui.dashboard.other_types', ['count' => $perType['other_types']]) }}</span>
                                <span class="ltr-text shrink-0 font-semibold text-slate-900">{{ number_format($perType['other']) }}</span>
                            </div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><div class="h-full rounded-full bg-slate-500" style="width: {{ round($perType['other'] / $max * 100) }}%"></div></div>
                        </li>
                    @endif
                </ul>
            @endif
        </section>

        <section aria-labelledby="coverage-heading" class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 id="coverage-heading" class="mb-3 text-base font-semibold text-slate-900">
                {{ __('ui.dashboard.coverage') }}
                <x-ui.help :text="__('ui.dashboard.help.coverage')" />
            </h2>
            @php $t = $counters['translations']; @endphp
            <dl class="grid grid-cols-2 gap-3 text-sm">
                @foreach (['both' => $t['both'], 'ar' => $t['ar'], 'en' => $t['en'], 'only_ar' => $t['only_ar'], 'only_en' => $t['only_en']] as $key => $value)
                    <div class="rounded-md bg-slate-50 p-3">
                        <dt class="text-slate-700">{{ __('ui.dashboard.cov_'.$key) }}</dt>
                        <dd class="mt-1 text-xl font-semibold text-slate-900"><span class="ltr-text">{{ number_format($value) }}</span></dd>
                    </div>
                @endforeach
                @if ($t['none'] > 0)
                    <div class="rounded-md border border-amber-300 bg-amber-50 p-3">
                        <dt class="text-amber-950">{{ __('ui.dashboard.cov_none') }}</dt>
                        <dd class="mt-1 text-xl font-semibold text-amber-950"><span class="ltr-text">{{ number_format($t['none']) }}</span></dd>
                    </div>
                @endif
            </dl>

            <h3 class="mb-2 mt-6 text-sm font-semibold text-slate-900">
                {{ __('ui.dashboard.quality') }}
                <x-ui.help :text="__('ui.dashboard.help.quality')" />
            </h3>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div class="rounded-md bg-slate-50 p-3">
                    <dt class="text-slate-700">{{ __('ui.dashboard.missing_city') }}</dt>
                    <dd class="mt-1 text-xl font-semibold text-slate-900"><span class="ltr-text">{{ number_format($counters['missing_city']) }}</span></dd>
                </div>
                <div class="rounded-md bg-slate-50 p-3">
                    <dt class="text-slate-700">{{ __('ui.dashboard.missing_phone') }}</dt>
                    <dd class="mt-1 text-xl font-semibold text-slate-900"><span class="ltr-text">{{ number_format($counters['missing_phone']) }}</span></dd>
                </div>
                <div class="col-span-2 rounded-md bg-slate-50 p-3">
                    <dt class="flex items-center gap-1 text-slate-700">{{ __('ui.dashboard.featured') }} <x-ui.help :text="__('ui.dashboard.help.featured')" /></dt>
                    <dd class="mt-1 text-xl font-semibold text-slate-900">
                        <a href="{{ route('featured.index') }}" class="hover:underline"><span class="ltr-text">{{ number_format($counters['featured']['total']) }}</span></a>
                        <span class="text-sm font-normal text-slate-600">{{ __('ui.dashboard.featured_active', ['count' => $counters['featured']['active']]) }}</span>
                    </dd>
                </div>
            </dl>
        </section>
    </div>

    @if ($legacy !== null)
        <section aria-labelledby="legacy-heading" class="mb-8 rounded-lg border border-amber-300 bg-amber-50 p-4">
            <h2 id="legacy-heading" class="text-base font-semibold text-amber-950">{{ __('ui.dashboard.legacy.title') }}</h2>
            <p class="mt-1 text-sm text-amber-950">{{ __('ui.dashboard.legacy.intro') }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach (['unpaired', 'suggestions', 'held', 'provisional'] as $key)
                    <div class="rounded-md bg-white p-3">
                        <dt class="text-sm text-slate-700">{{ __('ui.dashboard.legacy.'.$key) }}</dt>
                        <dd class="mt-1 text-xl font-semibold text-slate-900"><span class="ltr-text">{{ number_format($legacy[$key]) }}</span></dd>
                    </div>
                @endforeach
            </dl>
            <a href="{{ route('migration.review.index') }}" class="mt-3 inline-block text-sm font-medium text-teal-800 underline">{{ __('ui.dashboard.legacy.open_review') }}</a>
        </section>
    @endif

    <section aria-labelledby="recent-heading">
        <h2 id="recent-heading" class="mb-3 text-base font-semibold text-slate-900">
            {{ __('ui.dashboard.recent') }}
            <x-ui.help :text="__('ui.dashboard.help.recent')" />
        </h2>
        @if ($recent->isEmpty())
            <div class="rounded-lg border border-slate-200 bg-white"><x-ui.empty-state :title="__('ui.dashboard.no_data')" icon="building" /></div>
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
                        <tr>
                            <td class="px-3 py-2.5">
                                <a href="{{ route('providers.show', $provider) }}" class="font-medium text-teal-900 underline underline-offset-2" dir="auto">{{ $tr?->name ?? '—' }}</a>
                            </td>
                            <td class="hidden px-3 py-2.5 sm:table-cell" dir="auto">{{ $provider->type?->label($locale) ?? '—' }}</td>
                            <td class="px-3 py-2.5 whitespace-nowrap text-slate-700"><span class="ltr-text">{{ $provider->updated_at?->format('Y-m-d H:i') }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </section>
</div>
