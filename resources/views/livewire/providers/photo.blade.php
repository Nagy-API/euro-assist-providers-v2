<section class="ea-card mb-6 p-5" aria-labelledby="photo-heading">
    <div class="flex flex-wrap items-start gap-4">
        <x-ui.provider-image :provider="$provider" size="lg" wire:key="photo-{{ $provider->photo_path ?? 'none' }}" />
        <div class="min-w-0 flex-1">
            <h2 id="photo-heading" class="text-sm font-extrabold text-slate-900">{{ __('ui.media.photo') }}</h2>
            <p class="mt-1 text-sm text-slate-600">
                @if ($provider->photo_path !== null)
                    {{ __('ui.media.has_photo') }}
                @elseif ($provider->type?->icon_path !== null)
                    {{ __('ui.media.using_type_icon') }}
                @else
                    {{ __('ui.media.no_photo') }}
                @endif
            </p>

            @can('update', $provider)
                @unless ($provider->trashed())
                    <form wire:submit="save" class="mt-3 space-y-2">
                        <x-ui.file-input id="provider-photo" wire:model="photo" icon="photo" :button="$provider->photo_path !== null ? __('ui.media.replace') : __('ui.media.choose')"
                                         accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" aria-describedby="photo-hint" />
                        <p id="photo-hint" class="text-xs text-slate-600">{{ __('ui.media.hint', ['kb' => $maxKb]) }}</p>
                        <div wire:loading wire:target="photo"><x-ui.loading /></div>
                        @error('photo')<p id="photo-error" class="text-sm font-medium text-red-800" role="alert">{{ $message }}</p>@enderror
                        <div class="flex flex-wrap gap-2">
                            @if ($photo !== null && ! $errors->has('photo'))
                                <x-ui.button type="submit" icon="check-circle" wire:loading.attr="disabled" wire:target="save,photo">{{ __('ui.media.save') }}</x-ui.button>
                            @endif
                            @if ($provider->photo_path !== null)
                                <x-ui.button type="button" variant="danger-outline" icon="trash" wire:click="confirmRemove">{{ __('ui.media.remove') }}</x-ui.button>
                            @endif
                        </div>
                    </form>
                @endunless
            @endcan
        </div>
    </div>

    @if ($confirmingRemove)
        <x-ui.modal :title="__('ui.media.remove_title')" close="cancelRemove" size="sm">
            <p class="text-sm text-slate-700">{{ __('ui.media.remove_body') }}</p>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="cancelRemove">{{ __('ui.common.cancel') }}</x-ui.button>
                <x-ui.button variant="danger" icon="trash" wire:click="remove">{{ __('ui.media.remove') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <x-ui.toast :notice="$notice" />
</section>
