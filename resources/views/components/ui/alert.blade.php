@props(['tone' => 'info', 'title' => null, 'live' => false])
@php
    $styles = [
        'info' => 'border-sky-300 bg-sky-50 text-sky-950',
        'warning' => 'border-amber-400 bg-amber-50 text-amber-950',
        'error' => 'border-red-400 bg-red-50 text-red-950',
        'success' => 'border-green-400 bg-green-50 text-green-950',
    ];
    $icons = ['info' => 'info', 'warning' => 'exclamation', 'error' => 'exclamation', 'success' => 'check-circle'];
@endphp
<div {{ $attributes->merge(['class' => 'flex items-start gap-3 rounded-lg border px-4 py-3 text-sm '.($styles[$tone] ?? $styles['info'])]) }} @if($live) role="{{ $tone === 'error' ? 'alert' : 'status' }}" @endif>
    <x-ui.icon :name="$icons[$tone] ?? 'info'" class="mt-0.5" />
    <div class="min-w-0 flex-1">
        @if ($title)<p class="font-semibold">{{ $title }}</p>@endif
        <div>{{ $slot }}</div>
    </div>
</div>
