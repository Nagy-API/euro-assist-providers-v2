@props(['active', 'deleted' => false])
{{-- Status is conveyed by text and a distinct glyph, never by colour alone. --}}
@if ($deleted)
    <x-ui.badge tone="red"><span aria-hidden="true">&times;</span>{{ __('ui.status.deleted') }}</x-ui.badge>
@elseif ($active)
    <x-ui.badge tone="green"><span aria-hidden="true">&#10003;</span>{{ __('ui.status.active') }}</x-ui.badge>
@else
    <x-ui.badge tone="amber"><span aria-hidden="true">&#8211;</span>{{ __('ui.status.inactive') }}</x-ui.badge>
@endif
