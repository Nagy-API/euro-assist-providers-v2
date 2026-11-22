@props(['name', 'label', 'hint' => null, 'error' => null, 'id' => null])
@php
    $id ??= 'f-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $name);
    $message = $errors->first($error ?? $name);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($message ? $id.'-error' : ''));
@endphp
<div {{ $attributes->only('class') }}>
    <div class="flex items-start gap-2">
        <input id="{{ $id }}" name="{{ $name }}" type="checkbox" @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
               {{ $attributes->except('class')->merge(['class' => 'mt-0.5 size-5 shrink-0 rounded border-slate-500 text-teal-700']) }}>
        <label for="{{ $id }}" class="text-sm text-slate-800">{{ $label }}</label>
    </div>
    @if ($hint)<p id="{{ $id }}-hint" class="ms-7 mt-0.5 text-xs text-slate-600">{{ $hint }}</p>@endif
    @if ($message)<p id="{{ $id }}-error" class="ms-7 mt-1 text-sm font-medium text-red-800" role="alert">{{ $message }}</p>@endif
</div>
