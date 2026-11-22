@props([
    'name',
    'label',
    'type' => 'text',   // text|email|password|number|date|tel|url|select|textarea
    'hint' => null,
    'error' => null,    // error-bag key when it differs from the field name
    'required' => false,
    'id' => null,
    'rows' => 3,
    'labelHidden' => false,
])
@php
    $id ??= 'f-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $name);
    $message = $errors->first($error ?? $name);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($message ? $id.'-error' : ''));
    $control = 'block min-h-10 w-full rounded-md border bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-500 disabled:bg-slate-100 disabled:text-slate-600 '
        .($message ? 'border-red-700 ring-1 ring-red-700' : 'border-slate-500');
@endphp
<div {{ $attributes->only('class') }}>
    <label for="{{ $id }}" class="{{ $labelHidden ? 'sr-only' : 'mb-1 block text-sm font-medium text-slate-800' }}">
        {{ $label }}
        @if ($required)<span class="text-red-700" aria-hidden="true">*</span><span class="sr-only">({{ __('ui.common.required') }})</span>@endif
    </label>

    @if ($type === 'select')
        <select id="{{ $id }}" name="{{ $name }}" @if($describedBy) aria-describedby="{{ $describedBy }}" @endif @if($message) aria-invalid="true" @endif @if($required) aria-required="true" @endif
                {{ $attributes->except('class')->merge(['class' => $control]) }}>{{ $slot }}</select>
    @elseif ($type === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}" @if($describedBy) aria-describedby="{{ $describedBy }}" @endif @if($message) aria-invalid="true" @endif @if($required) aria-required="true" @endif
                  {{ $attributes->except('class')->merge(['class' => $control]) }}></textarea>
    @else
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" @if($describedBy) aria-describedby="{{ $describedBy }}" @endif @if($message) aria-invalid="true" @endif @if($required) aria-required="true" @endif
               {{ $attributes->except('class')->merge(['class' => $control]) }}>
    @endif

    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-1 text-xs text-slate-600">{{ $hint }}</p>
    @endif
    @if ($message)
        <p id="{{ $id }}-error" class="mt-1 text-sm font-medium text-red-800" role="alert">{{ $message }}</p>
    @endif
</div>
