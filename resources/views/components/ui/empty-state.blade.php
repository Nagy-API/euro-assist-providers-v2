@props(['title', 'icon' => 'search'])
<div {{ $attributes->merge(['class' => 'flex animate-enter flex-col items-center gap-2 px-4 py-14 text-center']) }}>
    <span class="mb-1 inline-flex size-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 ring-1 ring-brand-100"><x-ui.icon :name="$icon" class="size-7" /></span>
    <p class="text-base font-bold text-slate-900">{{ $title }}</p>
    @if (trim((string) $slot) !== '')
        <div class="max-w-md text-sm text-slate-600">{{ $slot }}</div>
    @endif
</div>
