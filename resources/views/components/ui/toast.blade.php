@props(['notice' => null])
{{--
    Notification region (role="status" is a polite live region). It is always present in the DOM
    so assistive technology announces content that is added to it. $notice: ['type' => success|error|info, 'message' => string].
--}}
<div class="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 sm:items-end sm:px-6" role="status" aria-live="polite" aria-atomic="true">
    @if ($notice)
        @php
            $type = $notice['type'] ?? 'info';
            $style = match ($type) {
                'success' => 'border-green-700 bg-green-50 text-green-950',
                'error' => 'border-red-700 bg-red-50 text-red-950',
                default => 'border-slate-500 bg-white text-slate-900',
            };
            $icon = match ($type) {
                'success' => 'check-circle',
                'error' => 'exclamation',
                default => 'info',
            };
        @endphp
        <div wire:key="notice-{{ $notice['id'] ?? md5($notice['message']) }}" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, {{ $type === 'error' ? 15000 : 7000 }})"
             class="pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-lg border-s-4 px-4 py-3 shadow-lg {{ $style }}">
            <x-ui.icon :name="$icon" class="mt-0.5 size-5" />
            <p class="flex-1 text-sm font-medium">{{ $notice['message'] }}</p>
            <button type="button" x-on:click="show = false" class="-me-2 -mt-1 inline-flex size-9 items-center justify-center rounded-md hover:bg-black/5" aria-label="{{ __('ui.common.dismiss') }}">
                <x-ui.icon name="x" class="size-4" />
            </button>
        </div>
    @endif
</div>
