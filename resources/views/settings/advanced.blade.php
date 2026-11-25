<x-layouts.app :title="__('ui.advanced.title')">
    <x-ui.page-header :title="__('ui.advanced.title')" :subtitle="__('ui.advanced.subtitle')" />

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="ea-card p-5" aria-labelledby="adv-migration">
            <h2 id="adv-migration" class="text-sm font-extrabold text-slate-900">{{ __('ui.advanced.migration_title') }}</h2>
            <p class="mt-2 text-sm text-slate-700">{{ __('ui.advanced.migration_text') }}</p>
            @if ($reviewAvailable)
                <x-ui.button class="mt-4" :href="route('migration.review.index')" variant="secondary" icon="arrows">{{ __('ui.advanced.migration_open') }}</x-ui.button>
            @else
                <x-ui.alert tone="info" class="mt-4">{{ __('ui.advanced.migration_unavailable') }}</x-ui.alert>
            @endif
        </section>

        <section class="ea-card p-5" aria-labelledby="adv-status">
            <h2 id="adv-status" class="text-sm font-extrabold text-slate-900">{{ __('ui.advanced.status_title') }}</h2>
            <p class="mt-2 text-sm text-slate-700">{{ __('ui.advanced.status_text') }}</p>
            <x-ui.button class="mt-4" :href="route('settings.status')" variant="secondary" icon="server">{{ __('ui.advanced.status_open') }}</x-ui.button>
        </section>

        @if ($auditAvailable)
            <section class="ea-card p-5" aria-labelledby="adv-audit">
                <h2 id="adv-audit" class="text-sm font-extrabold text-slate-900">{{ __('ui.advanced.audit_title') }}</h2>
                <p class="mt-2 text-sm text-slate-700">{{ __('ui.advanced.audit_text') }}</p>
                <x-ui.button class="mt-4" :href="route('audit.index')" variant="secondary" icon="clipboard">{{ __('ui.advanced.audit_open') }}</x-ui.button>
            </section>
        @endif
    </div>
</x-layouts.app>
