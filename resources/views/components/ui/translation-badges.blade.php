@props(['provider'])
{{--
    Which languages a record has, in words an employee can read without guessing:
    "Arabic ✓" when a translation is stored, a dashed "English —" chip when it is missing.
    Missing text is never generated; the screen-reader text states "available"/"missing" explicitly.
--}}
<span class="inline-flex flex-wrap items-center gap-1" role="group" aria-label="{{ __('ui.translation.coverage') }}">
    @foreach (config('providers.locales') as $locale)
        @php $has = $provider->translation($locale) !== null; @endphp
        <span title="{{ __('ui.locale.'.$locale) }}: {{ $has ? __('ui.translation.available') : __('ui.translation.missing') }}"
              class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[0.6875rem] font-bold whitespace-nowrap {{ $has ? 'border-green-200 bg-green-50 text-green-800' : 'border-dashed border-slate-300 bg-white text-slate-600' }}">
            <span lang="{{ $locale }}" aria-hidden="true">{{ __('ui.translation.short_'.$locale) }}</span>
            <span aria-hidden="true">{{ $has ? '✓' : '—' }}</span>
            <span class="sr-only">{{ __('ui.locale.'.$locale) }}: {{ $has ? __('ui.translation.available') : __('ui.translation.missing') }}</span>
        </span>
    @endforeach
</span>
