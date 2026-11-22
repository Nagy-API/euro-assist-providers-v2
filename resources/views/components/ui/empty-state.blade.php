@props(['title', 'icon' => 'search'])
<div {{ $attributes->merge(['class' => 'flex flex-col items-center gap-2 px-4 py-12 text-center']) }}>
    <span class="inline-flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-600"><x-ui.icon :name="$icon" class="size-6" /></span>
    <p class="text-base font-semibold text-slate-900">{{ $title }}</p>
    @if (trim((string) $slot) !== '')
        <div class="max-w-md text-sm text-slate-600">{{ $slot }}</div>
    @endif
</div>
