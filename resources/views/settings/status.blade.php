<x-layouts.app :title="__('ui.settings.title')">
    <x-ui.page-header :title="__('ui.settings.title')" :subtitle="__('ui.settings.subtitle')" />

    @foreach ($warnings as $warning)
        <x-ui.alert tone="warning" class="mb-4">{{ __('ui.settings.warn_'.$warning) }}</x-ui.alert>
    @endforeach

    <x-ui.alert tone="info" class="mb-6">{{ __('ui.settings.read_only') }}</x-ui.alert>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        @foreach ($groups as $group => $items)
            <section class="rounded-lg border border-slate-200 bg-white" aria-labelledby="g-{{ $group }}">
                <h2 id="g-{{ $group }}" class="border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-900">{{ __('ui.settings.groups.'.$group) }}</h2>
                <dl class="divide-y divide-slate-100 text-sm">
                    @foreach ($items as $key => $value)
                        <div class="grid grid-cols-2 gap-3 px-4 py-2.5">
                            <dt class="text-slate-600">{{ __('ui.settings.items.'.$key) }}</dt>
                            <dd class="break-words font-medium text-slate-900"><span class="ltr-text">{{ is_int($value) ? number_format($value) : $value }}</span></dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach
    </div>
</x-layouts.app>
