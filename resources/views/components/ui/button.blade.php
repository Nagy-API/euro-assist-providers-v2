@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'type' => 'button', 'icon' => null, 'flip' => false])
@php
    $variants = [
        'primary' => 'border-teal-700 bg-teal-700 text-white hover:bg-teal-800',
        'secondary' => 'border-slate-300 bg-white text-slate-800 hover:bg-slate-50',
        'danger' => 'border-red-700 bg-red-700 text-white hover:bg-red-800',
        'danger-outline' => 'border-red-300 bg-white text-red-800 hover:bg-red-50',
        'ghost' => 'border-transparent bg-transparent text-slate-700 hover:bg-slate-100',
    ];
    $sizes = ['md' => 'px-4', 'sm' => 'px-3', 'icon' => 'px-2.5'];
    $classes = 'inline-flex min-h-10 items-center justify-center gap-2 rounded-md border text-sm font-medium whitespace-nowrap transition-colors disabled:cursor-not-allowed disabled:opacity-60 '
        .($sizes[$size] ?? $sizes['md']).' '.($variants[$variant] ?? $variants['primary']);
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-ui.icon :name="$icon" :flip="$flip" class="size-4" />@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-ui.icon :name="$icon" :flip="$flip" class="size-4" />@endif
        {{ $slot }}
    </button>
@endif
