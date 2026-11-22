@props(['caption' => null])
{{-- The scroll container keeps wide tables from causing page-level horizontal overflow on small screens. --}}
<div {{ $attributes->merge(['class' => 'overflow-x-auto rounded-lg border border-slate-200 bg-white']) }} tabindex="0" role="region" @if($caption) aria-label="{{ $caption }}" @endif>
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        @if ($caption)<caption class="sr-only">{{ $caption }}</caption>@endif
        {{ $slot }}
    </table>
</div>
