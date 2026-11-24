@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'type' => 'button', 'icon' => null, 'flip' => false])
@php
    $variants = [
        // Euro Assist red, the Cards System working colour; white text on #d10000 is 5.6:1.
        'primary' => 'border-brand-600 bg-brand-600 text-white shadow-[0_6px_14px_-8px_rgba(209,0,0,0.9)] hover:border-brand-700 hover:bg-brand-700',
        'secondary' => 'border-slate-300 bg-white text-slate-800 shadow-xs hover:border-slate-400 hover:bg-slate-50',
        'danger' => 'border-red-600 bg-red-600 text-white hover:border-red-700 hover:bg-red-700',
        'danger-outline' => 'border-red-200 bg-white text-red-700 hover:border-red-300 hover:bg-red-50',
        'ghost' => 'border-transparent bg-transparent text-slate-700 hover:bg-slate-100 hover:text-slate-900',
    ];
    $sizes = ['md' => 'min-h-10 px-4 text-sm', 'sm' => 'min-h-9 px-3 text-sm', 'icon' => 'min-h-9 min-w-9 px-2'];
    $classes = 'inline-flex items-center justify-center gap-2 rounded-lg border font-semibold whitespace-nowrap transition duration-150 ease-brand active:translate-y-px disabled:pointer-events-none disabled:opacity-55 '
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
