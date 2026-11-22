@props(['tone' => 'slate'])
@php
    $tones = [
        'slate' => 'border-slate-300 bg-slate-100 text-slate-800',
        'green' => 'border-green-300 bg-green-50 text-green-900',
        'amber' => 'border-amber-300 bg-amber-50 text-amber-900',
        'red' => 'border-red-300 bg-red-50 text-red-900',
        'blue' => 'border-sky-300 bg-sky-50 text-sky-900',
        'teal' => 'border-teal-300 bg-teal-50 text-teal-900',
        'violet' => 'border-violet-300 bg-violet-50 text-violet-900',
        'outline' => 'border-dashed border-slate-400 bg-white text-slate-600',
    ];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap '.($tones[$tone] ?? $tones['slate'])]) }}>{{ $slot }}</span>
