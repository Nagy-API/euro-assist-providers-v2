{{--
    File picker with a translated button and file-name label. Native file inputs render their text in
    the browser's language ("Choose File / No file chosen") regardless of the page language and
    direction, so the real input is visually hidden (still focusable and announced) and labelled here.
--}}
@props(['id', 'accept' => null, 'button' => null, 'icon' => 'upload'])
<div class="flex min-w-0 flex-wrap items-center gap-2" x-data="{ name: '' }">
    <label for="{{ $id }}" class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border border-brand-200 bg-brand-50 px-4 text-sm font-bold text-brand-800 hover:bg-brand-100 focus-within:ring-2 focus-within:ring-brand-500 focus-within:ring-offset-2">
        <x-ui.icon :name="$icon" class="size-4" />
        {{ $button ?? __('ui.common.choose_file') }}
        <input id="{{ $id }}" type="file" @if ($accept) accept="{{ $accept }}" @endif {{ $attributes->merge(['class' => 'sr-only']) }}
               x-on:change="name = $event.target.files[0]?.name ?? ''">
    </label>
    <span class="min-w-0 max-w-full truncate text-sm text-slate-700" dir="auto" x-text="name || @js(__('ui.common.no_file_chosen'))">{{ __('ui.common.no_file_chosen') }}</span>
</div>
