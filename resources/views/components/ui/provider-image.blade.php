{{--
    Provider picture (ADR-018): the provider's own photo, else the V1 per-type fallback icon, else a
    neutral placeholder. Every image is served by an authorized private route; nothing is public.
--}}
@props(['provider', 'size' => 'md'])
@php
    $classes = ['sm' => 'size-10 rounded-lg', 'md' => 'size-16 rounded-xl', 'lg' => 'size-28 rounded-2xl'][$size] ?? 'size-16 rounded-xl';
    $photo = $provider->photo_path !== null && ! $provider->trashed();
    $icon = ! $photo && $provider->type?->icon_path !== null;
    $src = $photo ? route('providers.photo', ['provider' => $provider, 'v' => substr(sha1((string) $provider->photo_path), 0, 10)])
        : ($icon ? route('types.icon', ['providerType' => $provider->provider_type_id, 'v' => substr(sha1((string) $provider->type?->icon_path), 0, 10)]) : null);
@endphp
<span {{ $attributes->class([$classes, 'inline-flex shrink-0 items-center justify-center overflow-hidden border border-slate-200 bg-white']) }}>
    @if ($src !== null)
        <img src="{{ $src }}" alt="{{ $photo ? __('ui.media.photo_alt') : __('ui.media.type_icon_alt') }}" loading="lazy" decoding="async" class="size-full object-contain p-0.5">
    @else
        <x-ui.icon name="building" class="size-1/2 text-slate-400" aria-hidden="true" />
    @endif
</span>
