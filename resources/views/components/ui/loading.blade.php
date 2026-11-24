@props(['target' => null])
{{-- Shown by Livewire only while a request is in flight (optionally for specific actions). --}}
<span wire:loading @if($target) wire:target="{{ $target }}" @endif {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-sm font-medium text-slate-700 shadow-xs ring-1 ring-slate-200']) }} role="status">
    <x-ui.icon name="spinner" class="size-4 text-brand-600" />
    <span>{{ __('ui.common.loading') }}</span>
</span>
