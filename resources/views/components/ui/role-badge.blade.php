@props(['role'])
@php
    $tone = match ($role->value) {
        'admin' => 'violet',
        'editor' => 'blue',
        default => 'slate',
    };
@endphp
<x-ui.badge :tone="$tone">{{ $role->label() }}</x-ui.badge>
