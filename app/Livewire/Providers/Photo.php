<?php

namespace App\Livewire\Providers;

use App\Livewire\Concerns\HasNotices;
use App\Models\Provider;
use App\Services\AuditLogger;
use App\Services\Media\ProviderPhotoStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Upload, replace or remove a provider's photo (ADR-018). Viewers see the picture; only users who
 * may update the provider can obtain an upload URL, save or remove. The file is validated by its
 * decoded image type and stored privately with a random name (ProviderPhotoStore).
 */
class Photo extends Component
{
    use HasNotices;
    use WithFileUploads {
        _startUpload as private traitStartUpload;
        _finishUpload as private traitFinishUpload;
    }

    #[Locked]
    public int $providerId;

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    public bool $confirmingRemove = false;

    public function mount(Provider $provider): void
    {
        Gate::authorize('view', $provider);
        $this->providerId = (int) $provider->id;
    }

    /**
     * @param  string  $name
     * @param  array<int, array<string, mixed>>  $fileInfo
     * @param  bool  $isMultiple
     */
    #[Renderless]
    public function _startUpload($name, $fileInfo, $isMultiple): void
    {
        Gate::authorize('update', $this->writable());
        $this->traitStartUpload($name, $fileInfo, $isMultiple);
    }

    /**
     * @param  string  $name
     * @param  array<int, string>  $tmpPath
     * @param  bool  $isMultiple
     * @param  bool  $append
     */
    public function _finishUpload($name, $tmpPath, $isMultiple, $append = true): void
    {
        Gate::authorize('update', $this->writable());
        $this->traitFinishUpload($name, $tmpPath, $isMultiple, $append);
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        // `image` excludes SVG; the explicit mimes list pins the allowed formats.
        return ['photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.(int) config('providers.media.max_photo_kb', 2048)]];
    }

    public function updatedPhoto(): void
    {
        Gate::authorize('update', $this->writable());
        try {
            $this->validateOnly('photo', $this->rules(), [], ['photo' => __('ui.media.photo')]);
            if ($this->photo !== null) {
                app(ProviderPhotoStore::class)->inspect($this->photo, 'photo');
            }
        } catch (ValidationException $e) {
            $this->photo = null;
            throw $e;
        }
    }

    public function save(ProviderPhotoStore $store, AuditLogger $audit): void
    {
        $provider = $this->writable();
        Gate::authorize('update', $provider);
        $this->validate($this->rules(), [], ['photo' => __('ui.media.photo')]);

        $new = $store->store($this->photo, 'photo'); // re-validates the real image type
        try {
            $old = DB::transaction(function () use ($new) {
                $p = Provider::query()->lockForUpdate()->findOrFail($this->providerId);
                $old = $p->photo_path;
                // A photo change is a provider edit: the revision moves (concurrent forms and the
                // migration rollback both rely on it).
                $p->forceFill(['photo_path' => $new, 'revision' => $p->revision + 1])->save();

                return $old;
            });
        } catch (\Throwable $e) {
            $store->delete($new); // never leave an orphan file behind
            throw $e;
        }
        if ($old !== null && $old !== $new) {
            $store->delete($old);
        }
        $audit->record($old === null ? 'provider.photo.added' : 'provider.photo.replaced', $provider, 'success', ['provider_id' => $provider->id]);

        $this->photo = null;
        $this->notify(__('ui.media.saved'));
    }

    public function confirmRemove(): void
    {
        Gate::authorize('update', $this->writable());
        $this->confirmingRemove = true;
    }

    public function cancelRemove(): void
    {
        $this->confirmingRemove = false;
    }

    public function remove(ProviderPhotoStore $store, AuditLogger $audit): void
    {
        $provider = $this->writable();
        Gate::authorize('update', $provider);
        $old = DB::transaction(function () {
            $p = Provider::query()->lockForUpdate()->findOrFail($this->providerId);
            $old = $p->photo_path;
            $p->forceFill(['photo_path' => null, 'revision' => $p->photo_path === null ? $p->revision : $p->revision + 1])->save();

            return $old;
        });
        $store->delete($old);
        if ($old !== null) {
            $audit->record('provider.photo.removed', $provider, 'success', ['provider_id' => $provider->id]);
        }
        $this->confirmingRemove = false;
        $this->notify(__('ui.media.removed'));
    }

    /** The provider for a write: a deleted provider (or merge shell) is never changed here. */
    private function writable(): Provider
    {
        $provider = $this->provider();
        abort_if($provider->trashed(), 404);

        return $provider;
    }

    private function provider(): Provider
    {
        return Provider::query()->withTrashed()->with('type')->findOrFail($this->providerId);
    }

    public function render(): View
    {
        return view('livewire.providers.photo', [
            'provider' => $this->provider(),
            'maxKb' => (int) config('providers.media.max_photo_kb', 2048),
        ]);
    }
}
