@props(['text', 'id' => null])
@php $id ??= 'help-'.substr(md5($text), 0, 8); @endphp
{{-- Tooltip shown on hover and keyboard focus; also announced through aria-describedby. --}}
<span class="group relative inline-flex align-middle">
    <button type="button" class="inline-flex size-6 items-center justify-center rounded-full text-slate-600 hover:text-slate-900" aria-describedby="{{ $id }}" aria-label="{{ __('ui.common.help') }}">
        <x-ui.icon name="question" class="size-4" />
    </button>
    <span id="{{ $id }}" role="tooltip" class="pointer-events-none invisible absolute start-0 top-full z-20 mt-1 w-64 rounded-md bg-slate-900 px-3 py-2 text-xs font-normal leading-relaxed text-white shadow-lg group-focus-within:visible group-hover:visible">{{ $text }}</span>
</span>
