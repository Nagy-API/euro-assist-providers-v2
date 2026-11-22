{{-- Livewire paginator view (use via paginationView() in components). Logical utilities keep it RTL-safe. --}}
@php
    $page = $paginator->getPageName();
    $btn = 'inline-flex min-h-10 min-w-10 items-center justify-center rounded-md border px-3 text-sm font-medium';
@endphp
<div>
    @if ($paginator->total() > 0)
        <nav role="navigation" aria-label="{{ __('ui.pagination.label') }}" class="flex flex-col items-center gap-3 sm:flex-row sm:justify-between">
            <p class="text-sm text-slate-700">
                {{ __('ui.pagination.showing', ['from' => $paginator->firstItem(), 'to' => $paginator->lastItem(), 'total' => $paginator->total()]) }}
            </p>

            @if ($paginator->hasPages())
                <div class="flex flex-wrap items-center justify-center gap-1">
                    @if ($paginator->onFirstPage())
                        <span class="{{ $btn }} border-slate-200 bg-slate-50 text-slate-500" aria-disabled="true"><x-ui.icon name="chevron-left" flip class="size-4" /><span class="sr-only">{{ __('ui.pagination.previous') }}</span></span>
                    @else
                        <button type="button" wire:click="previousPage('{{ $page }}')" wire:loading.attr="disabled" class="{{ $btn }} border-slate-300 bg-white text-slate-800 hover:bg-slate-50"><x-ui.icon name="chevron-left" flip class="size-4" /><span class="sr-only">{{ __('ui.pagination.previous') }}</span></button>
                    @endif

                    @foreach ($elements as $element)
                        @if (is_string($element))
                            <span class="px-1 text-slate-500" aria-hidden="true">{{ $element }}</span>
                        @endif
                        @if (is_array($element))
                            @foreach ($element as $number => $url)
                                @if ($number == $paginator->currentPage())
                                    <span aria-current="page" class="{{ $btn }} border-teal-700 bg-teal-700 text-white">{{ $number }}</span>
                                @else
                                    <button type="button" wire:key="pg-{{ $page }}-{{ $number }}" wire:click="gotoPage({{ $number }}, '{{ $page }}')" class="{{ $btn }} border-slate-300 bg-white text-slate-800 hover:bg-slate-50" aria-label="{{ __('ui.pagination.goto', ['page' => $number]) }}">{{ $number }}</button>
                                @endif
                            @endforeach
                        @endif
                    @endforeach

                    @if ($paginator->hasMorePages())
                        <button type="button" wire:click="nextPage('{{ $page }}')" wire:loading.attr="disabled" class="{{ $btn }} border-slate-300 bg-white text-slate-800 hover:bg-slate-50"><x-ui.icon name="chevron-right" flip class="size-4" /><span class="sr-only">{{ __('ui.pagination.next') }}</span></button>
                    @else
                        <span class="{{ $btn }} border-slate-200 bg-slate-50 text-slate-500" aria-disabled="true"><x-ui.icon name="chevron-right" flip class="size-4" /><span class="sr-only">{{ __('ui.pagination.next') }}</span></span>
                    @endif
                </div>
            @endif
        </nav>
    @endif
</div>
