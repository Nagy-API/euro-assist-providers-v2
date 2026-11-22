@props(['path', 'show' => false])
{{-- Admin-only explicit "clear this value" (ADR-008). Rendered only when the viewer holds providers.clear_values and a value is stored. --}}
@if ($show)
    <label class="mt-1 inline-flex min-h-8 items-center gap-2 text-xs font-medium text-red-900">
        <input type="checkbox" wire:model.live="clear" value="{{ $path }}" class="size-4 rounded border-slate-500 text-red-700">
        {{ __('ui.form.clear_value') }}
    </label>
@endif
