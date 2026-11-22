@props(['provider'])
{{-- Translation presence as text (AR / EN) plus an explicit availability word for screen readers. --}}
<span class="inline-flex items-center gap-1" role="group" aria-label="{{ __('ui.translation.coverage') }}">
    @foreach (config('providers.locales') as $locale)
        @php $has = $provider->translation($locale) !== null; @endphp
        <x-ui.badge :tone="$has ? 'teal' : 'outline'">
            <span class="ltr-text">{{ strtoupper($locale) }}</span>
            <span class="sr-only">{{ __('ui.locale.'.$locale) }}: {{ $has ? __('ui.translation.available') : __('ui.translation.missing') }}</span>
            @unless ($has)<span aria-hidden="true">&times;</span>@endunless
        </x-ui.badge>
    @endforeach
</span>
