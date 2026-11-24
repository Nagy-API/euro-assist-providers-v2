@props(['tone' => 'info', 'title' => null, 'live' => false])
@php
    $styles = [
        'info' => 'border-sky-200 border-s-sky-600 bg-sky-50 text-sky-950',
        'warning' => 'border-amber-300 border-s-amber-500 bg-amber-50 text-amber-950',
        'error' => 'border-red-200 border-s-red-600 bg-red-50 text-red-950',
        'success' => 'border-green-200 border-s-green-600 bg-green-50 text-green-950',
    ];
    $iconColor = ['info' => 'text-sky-700', 'warning' => 'text-amber-700', 'error' => 'text-red-700', 'success' => 'text-green-700'];
    $icons = ['info' => 'info', 'warning' => 'exclamation', 'error' => 'exclamation', 'success' => 'check-circle'];
@endphp
<div {{ $attributes->merge(['class' => 'flex animate-enter items-start gap-3 rounded-xl border border-s-4 px-4 py-3 text-sm '.($styles[$tone] ?? $styles['info'])]) }} @if($live) role="{{ $tone === 'error' ? 'alert' : 'status' }}" @endif>
    <x-ui.icon :name="$icons[$tone] ?? 'info'" class="mt-0.5 {{ $iconColor[$tone] ?? $iconColor['info'] }}" />
    <div class="min-w-0 flex-1">
        @if ($title)<p class="font-bold">{{ $title }}</p>@endif
        <div>{{ $slot }}</div>
    </div>
</div>
