@props(['tone' => 'slate'])
@php
    $tones = [
        'slate' => 'border-slate-200 bg-slate-100 text-slate-700',
        'green' => 'border-green-200 bg-green-50 text-green-800',
        'amber' => 'border-amber-200 bg-amber-50 text-amber-900',
        'red' => 'border-red-200 bg-red-50 text-red-800',
        'blue' => 'border-sky-200 bg-sky-50 text-sky-800',
        'brand' => 'border-brand-200 bg-brand-50 text-brand-800',
        'teal' => 'border-brand-200 bg-brand-50 text-brand-800', // legacy alias of "brand"
        'violet' => 'border-slate-800 bg-slate-800 text-white',
        'outline' => 'border-dashed border-slate-300 bg-white text-slate-600',
    ];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap '.($tones[$tone] ?? $tones['slate'])]) }}>{{ $slot }}</span>
