@props(['text', 'id' => null])
@php $id ??= 'help-'.substr(md5($text), 0, 8); @endphp
{{-- Tooltip shown on hover and keyboard focus; also announced through aria-describedby (a hidden
     description is still read). display:none while closed so it never widens a phone layout. --}}
<span class="group relative inline-flex align-middle">
    <button type="button" class="inline-flex size-6 items-center justify-center rounded-full text-slate-500 transition-colors hover:text-brand-700" aria-describedby="{{ $id }}" aria-label="{{ __('ui.common.help') }}">
        <x-ui.icon name="question" class="size-4" />
    </button>
    <span id="{{ $id }}" role="tooltip" class="pointer-events-none hidden absolute start-0 top-full z-20 mt-1 w-64 max-w-[calc(100vw-2rem)] rounded-lg bg-slate-900 px-3 py-2 text-xs font-normal leading-relaxed text-white shadow-lg group-focus-within:block group-hover:block">{{ $text }}</span>
</span>
