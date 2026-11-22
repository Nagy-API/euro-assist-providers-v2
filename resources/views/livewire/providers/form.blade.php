@use('App\Models\ProviderTranslation')
@php
    $isRtl = fn (string $l) => in_array($l, config('providers.rtl_locales'), true);
    $longFields = ['specialization', 'hours', 'discount_text', 'package_text'];
    $clearableTranslation = \App\Services\ProviderService::CLEARABLE_TRANSLATION_FIELDS;
@endphp
<div>
    <nav aria-label="{{ __('ui.common.breadcrumb') }}" class="mb-3 text-sm">
        <a href="{{ $editing ? route('providers.show', $providerId) : route('providers.index') }}" class="inline-flex min-h-8 items-center gap-1 font-semibold text-brand-700 underline decoration-brand-200 underline-offset-4 hover:decoration-brand-600"><x-ui.icon name="chevron-left" flip class="size-4" />{{ __('ui.common.back') }}</a>
    </nav>

    <x-ui.page-header :title="$editing ? __('ui.form.edit_title') : __('ui.form.create_title')" :subtitle="$editing ? __('ui.form.blank_preserves') : __('ui.form.create_subtitle')" />

    @if ($conflict)
        <x-ui.alert tone="error" class="mb-6" :live="true" :title="__('ui.form.conflict_title')">
            <p>{{ __('ui.form.conflict_body') }}</p>
            <div class="mt-2"><x-ui.button size="sm" variant="secondary" wire:click="reload" icon="restore">{{ __('ui.form.reload_latest') }}</x-ui.button></div>
        </x-ui.alert>
    @endif

    @if ($errors->any())
        <x-ui.alert tone="error" class="mb-6" :live="true" :title="__('ui.form.fix_errors')">
            <ul class="list-disc ps-5">
                @foreach (collect($errors->all())->unique()->take(10) as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <form wire:submit="save" novalidate class="space-y-6">
        {{-- Core data --}}
        <section class="ea-card p-5 sm:p-5" aria-labelledby="core-h">
            <h2 id="core-h" class="mb-4 text-base font-extrabold text-slate-900">{{ __('ui.form.core') }}</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field name="provider_type_id" type="select" :label="__('ui.providers.type')" wire:model="provider_type_id" required>
                    <option value="">{{ __('ui.common.select_placeholder') }}</option>
                    @foreach ($types as $t)
                        <option value="{{ $t->id }}">{{ $t->label($uiLocale) }}@unless ($t->is_active) ({{ __('ui.status.inactive') }})@endunless</option>
                    @endforeach
                </x-ui.field>
                <div class="self-end pb-1">
                    <x-ui.checkbox name="is_active" :label="__('ui.form.is_active')" :hint="__('ui.form.is_active_hint')" wire:model="is_active" />
                </div>
            </div>
        </section>

        {{-- Translations: one form, one tab per language. A language may be left empty; nothing is auto-translated. --}}
        <section class="ea-card" aria-labelledby="tr-h" x-data="{ tab: $wire.$entangle('tab') }">
            <div class="border-b border-slate-200 px-4 pt-4 sm:px-5">
                <h2 id="tr-h" class="text-base font-extrabold text-slate-900">{{ __('ui.form.translations') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('ui.form.translations_hint') }}</p>
                <div role="tablist" aria-labelledby="tr-h" class="-mb-px mt-3 flex gap-1"
                     x-on:keydown.arrow-left.prevent="tab = tab === '{{ $locales[0] }}' ? '{{ $locales[1] ?? $locales[0] }}' : '{{ $locales[0] }}'; $nextTick(() => $refs['tab_' + tab].focus())"
                     x-on:keydown.arrow-right.prevent="tab = tab === '{{ $locales[0] }}' ? '{{ $locales[1] ?? $locales[0] }}' : '{{ $locales[0] }}'; $nextTick(() => $refs['tab_' + tab].focus())">
                    @foreach ($locales as $loc)
                        <button type="button" role="tab" id="tab-{{ $loc }}" x-ref="tab_{{ $loc }}" aria-controls="panel-{{ $loc }}"
                                x-bind:aria-selected="(tab === '{{ $loc }}').toString()" x-bind:tabindex="tab === '{{ $loc }}' ? 0 : -1" x-on:click="tab = '{{ $loc }}'"
                                x-bind:class="tab === '{{ $loc }}' ? 'border-brand-700 bg-white text-brand-900' : 'border-transparent text-slate-700 hover:bg-slate-50'"
                                class="inline-flex min-h-10 items-center gap-2 rounded-t-md border-x border-t border-b-2 px-4 text-sm font-medium">
                            {{ __('ui.locale.'.$loc) }}
                            @if ($errorLocales[$loc])
                                <span class="inline-flex size-5 items-center justify-center rounded-full bg-red-700 text-xs font-bold text-white" aria-hidden="true">!</span>
                                <span class="sr-only">{{ __('ui.form.tab_has_errors') }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>

            @foreach ($locales as $loc)
                <div role="tabpanel" id="panel-{{ $loc }}" aria-labelledby="tab-{{ $loc }}" x-show="tab === '{{ $loc }}'" x-cloak class="p-4 sm:p-5" lang="{{ $loc }}">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field :name="'translations.'.$loc.'.name'" :label="__('ui.providers.fields.name')" wire:model="translations.{{ $loc }}.name" dir="{{ $isRtl($loc) ? 'rtl' : 'ltr' }}" maxlength="255" :hint="__('ui.form.required_if_language')" />
                        <x-ui.field :name="'translations.'.$loc.'.address'" :label="__('ui.providers.fields.address')" wire:model="translations.{{ $loc }}.address" dir="{{ $isRtl($loc) ? 'rtl' : 'ltr' }}" maxlength="500" :hint="__('ui.form.required_if_language')" />

                        @foreach (['city', 'district'] as $f)
                            @php $path = "translations.$loc.$f"; @endphp
                            <div>
                                <x-ui.field :name="$path" :label="__('ui.providers.fields.'.$f)" wire:model="translations.{{ $loc }}.{{ $f }}" dir="{{ $isRtl($loc) ? 'rtl' : 'ltr' }}" maxlength="255" :disabled="in_array($path, $clear, true)" />
                                <x-ui.clear-toggle :path="$path" :show="$canClear && ($storedFlags[$path] ?? false)" />
                            </div>
                        @endforeach

                        @foreach ($longFields as $f)
                            @php $path = "translations.$loc.$f"; @endphp
                            <div class="sm:col-span-2">
                                <x-ui.field :name="$path" type="textarea" :rows="3" :label="__('ui.providers.fields.'.$f)" wire:model="translations.{{ $loc }}.{{ $f }}" dir="{{ $isRtl($loc) ? 'rtl' : 'ltr' }}" :disabled="in_array($path, $clear, true)" />
                                <x-ui.clear-toggle :path="$path" :show="$canClear && ($storedFlags[$path] ?? false)" />
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </section>

        {{-- Contact and location (language independent) --}}
        <section class="ea-card p-5 sm:p-5" aria-labelledby="contact-h">
            <h2 id="contact-h" class="mb-4 text-base font-extrabold text-slate-900">{{ __('ui.providers.contact') }}</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach ([
                    ['phone', 'text', 'ui.form.phone_hint'],
                    ['email', 'email', null],
                    ['website', 'text', 'ui.form.website_hint'],
                    ['map_url', 'text', 'ui.form.map_hint'],
                    ['latitude', 'text', 'ui.form.lat_hint'],
                    ['longitude', 'text', 'ui.form.lng_hint'],
                ] as [$f, $t, $hint])
                    <div>
                        <x-ui.field :name="$f" :type="$t" :label="__('ui.providers.'.$f)" wire:model="{{ $f }}" dir="ltr" :hint="$hint ? __($hint) : null" :disabled="in_array($f, $clear, true)" autocomplete="off" />
                        <x-ui.clear-toggle :path="$f" :show="$canClear && ($storedFlags[$f] ?? false)" />
                    </div>
                @endforeach
            </div>
        </section>

        @if ($canClear)
            <x-ui.alert tone="warning">{{ __('ui.form.clear_admin_note') }}</x-ui.alert>
        @endif

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <x-ui.button variant="secondary" :href="$editing ? route('providers.show', $providerId) : route('providers.index')">{{ __('ui.common.cancel') }}</x-ui.button>
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ $editing ? __('ui.form.save_changes') : __('ui.form.create_provider') }}</span>
                <span wire:loading wire:target="save" class="inline-flex items-center gap-2"><x-ui.icon name="spinner" class="size-4" />{{ __('ui.common.saving') }}</span>
            </x-ui.button>
        </div>
    </form>

    <x-ui.toast :notice="$notice" />
</div>
