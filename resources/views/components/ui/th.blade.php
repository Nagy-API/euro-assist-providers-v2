@props(['sort' => null, 'current' => null, 'dir' => 'asc', 'action' => 'sortBy'])
{{-- Header cell; sortable when $sort is given (Livewire method $action receives the column key). --}}
@php
    $isCurrent = $sort !== null && $current === $sort;
    $ariaSort = $isCurrent ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
@endphp
<th scope="col" @if($sort) aria-sort="{{ $ariaSort }}" @endif {{ $attributes->merge(['class' => 'bg-slate-50 px-3 py-2.5 text-start text-xs font-semibold text-slate-700']) }}>
    @if ($sort)
        <button type="button" wire:click="{{ $action }}('{{ $sort }}')" class="-mx-1 inline-flex min-h-8 items-center gap-1 rounded px-1 hover:text-slate-900">
            {{ $slot }}
            <span aria-hidden="true" class="text-slate-500">{{ $isCurrent ? ($dir === 'asc' ? '▲' : '▼') : '↕' }}</span>
        </button>
    @else
        {{ $slot }}
    @endif
</th>
