@props(['sort' => null, 'current' => null, 'dir' => 'asc', 'action' => 'sortBy'])
{{-- Header cell; sortable when $sort is given (Livewire method $action receives the column key). --}}
@php
    $isCurrent = $sort !== null && $current === $sort;
    $ariaSort = $isCurrent ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
@endphp
<th scope="col" @if($sort) aria-sort="{{ $ariaSort }}" @endif {{ $attributes->merge(['class' => 'bg-slate-50 px-3 py-3 text-start text-xs font-bold text-slate-600']) }}>
    @if ($sort)
        <button type="button" wire:click="{{ $action }}('{{ $sort }}')" class="-mx-1 inline-flex min-h-8 items-center gap-1 rounded px-1 transition-colors hover:text-slate-900 {{ $isCurrent ? 'text-slate-900' : '' }}">
            {{ $slot }}
            <span aria-hidden="true" class="{{ $isCurrent ? 'text-brand-600' : 'text-slate-400' }}">{{ $isCurrent ? ($dir === 'asc' ? '▲' : '▼') : '↕' }}</span>
        </button>
    @else
        {{ $slot }}
    @endif
</th>
