@props(['target' => null])
{{-- Shown by Livewire only while a request is in flight (optionally for specific actions). --}}
<span wire:loading @if($target) wire:target="{{ $target }}" @endif {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 text-sm text-slate-600']) }} role="status">
    <x-ui.icon name="spinner" class="size-4" />
    <span>{{ __('ui.common.loading') }}</span>
</span>
