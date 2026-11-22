@props(['title', 'subtitle' => null, 'eyebrow' => null])
<div class="mb-6 flex animate-enter flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div class="min-w-0">
        @if ($eyebrow)
            <p class="mb-1 flex items-center gap-2 text-xs font-bold tracking-[0.12em] text-brand-700 uppercase"><span class="h-px w-6 bg-brand-500" aria-hidden="true"></span>{{ $eyebrow }}</p>
        @endif
        <h1 class="text-2xl leading-tight font-extrabold tracking-tight text-slate-900 sm:text-[1.75rem]">{{ $title }}</h1>
        @if ($subtitle)<p class="mt-1 max-w-3xl text-sm text-slate-600 sm:text-[0.9375rem]">{{ $subtitle }}</p>@endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
