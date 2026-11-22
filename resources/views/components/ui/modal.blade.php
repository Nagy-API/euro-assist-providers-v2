@props(['title', 'close' => 'closeModal', 'size' => 'md', 'description' => null])
{{--
    Accessible dialog. Render it conditionally from the Livewire component (@if ($open)) so it exists
    only while open. Initial focus: the first [data-autofocus] element, else the first focusable one.
    Escape and the backdrop call the Livewire method named by $close. Focus is trapped while open
    and returned to the opener on removal (see Alpine.data('dialog') in resources/js/app.js).
    Entrance motion is a short fade + rise (disabled by prefers-reduced-motion).
--}}
@php
    $uid = 'dlg-'.substr(md5($title.$close), 0, 8);
    $widths = ['sm' => 'max-w-md', 'md' => 'max-w-xl', 'lg' => 'max-w-3xl'];
@endphp
<div x-data="dialog" x-on:keydown.escape.stop="$wire.{{ $close }}()" x-on:keydown.tab="trap($event)"
     class="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-4">
    <div class="fixed inset-0 animate-fade bg-slate-950/55" aria-hidden="true" wire:click="{{ $close }}"></div>
    <div role="dialog" aria-modal="true" aria-labelledby="{{ $uid }}-title" @if($description) aria-describedby="{{ $uid }}-desc" @endif tabindex="-1"
         class="relative flex max-h-[92vh] w-full animate-pop flex-col overflow-hidden rounded-t-2xl bg-white shadow-lg ring-1 ring-slate-900/5 sm:rounded-2xl {{ $widths[$size] ?? $widths['md'] }}">
        <div class="h-1 shrink-0 bg-[image:var(--ea-hero)]" aria-hidden="true"></div>
        <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
            <div>
                <h2 id="{{ $uid }}-title" class="text-lg font-extrabold text-slate-900">{{ $title }}</h2>
                @if ($description)<p id="{{ $uid }}-desc" class="mt-1 text-sm text-slate-600">{{ $description }}</p>@endif
            </div>
            <button type="button" wire:click="{{ $close }}" class="-me-2 -mt-1 inline-flex size-10 items-center justify-center rounded-lg text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900" aria-label="{{ __('ui.common.close') }}">
                <x-ui.icon name="x" />
            </button>
        </div>
        <div class="overflow-y-auto px-5 py-4">{{ $slot }}</div>
        @isset($footer)
            <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3 sm:flex-row sm:justify-end">{{ $footer }}</div>
        @endisset
    </div>
</div>
