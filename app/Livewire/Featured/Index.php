<?php

namespace App\Livewire\Featured;

use App\Livewire\Concerns\HasNotices;
use App\Models\FeaturedProvider;
use App\Models\ProviderType;
use App\Services\AuditLogger;
use App\Services\Ui\FeaturedLogoStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Featured providers (brand presentation records, provisional D5). Anyone with providers.view may
 * list them read-only; create/update/delete require featured.manage (Admin). Logos are validated
 * as real images and stored privately by FeaturedLogoStore.
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    use HasNotices, WithPagination;
    use WithFileUploads {
        _startUpload as private traitStartUpload;
        _finishUpload as private traitFinishUpload;
    }

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $deletingId = null;

    public string $name_en = '';

    public string $name_ar = '';

    public string $provider_type_id = '';

    public string $sort_order = '0';

    public bool $is_active = true;

    public bool $removeLogo = false;

    /** @var TemporaryUploadedFile|null */
    public $logo = null;

    public function paginationView(): string
    {
        return 'pagination.ui';
    }

    public function mount(): void
    {
        $this->authorize('viewAny', FeaturedProvider::class);
    }

    // Upload entry points: only featured managers may even obtain an upload URL or finish an upload.
    /**
     * @param  string  $name
     * @param  array<int, array<string, mixed>>  $fileInfo
     * @param  bool  $isMultiple
     */
    #[Renderless]
    public function _startUpload($name, $fileInfo, $isMultiple): void
    {
        Gate::authorize('featured.manage');
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
        Gate::authorize('featured.manage');
        $this->traitFinishUpload($name, $tmpPath, $isMultiple, $append);
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'name_en' => ['nullable', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'provider_type_id' => ['nullable', 'integer', 'exists:provider_types,id'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['boolean'],
            'removeLogo' => ['boolean'],
            // `image` excludes SVG; the explicit mimes list pins the allowed formats.
            'logo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.(int) config('providers.featured.max_logo_kb', 1024)],
        ];
    }

    /** @return array<string, string> */
    private function attributeNames(): array
    {
        return [
            'name_en' => __('ui.featured.name_en'), 'name_ar' => __('ui.featured.name_ar'),
            'provider_type_id' => __('ui.providers.type'), 'sort_order' => __('ui.featured.sort_order'), 'logo' => __('ui.featured.logo'),
        ];
    }

    /** Validate immediately after a file is chosen; an invalid file is dropped from the component. */
    public function updatedLogo(): void
    {
        Gate::authorize('featured.manage');
        try {
            $this->validateOnly('logo', $this->rules(), [], $this->attributeNames());
            if ($this->logo !== null) {
                app(FeaturedLogoStore::class)->inspect($this->logo); // real image type, not the client's claim
            }
        } catch (ValidationException $e) {
            $this->logo = null;
            throw $e;
        }
    }

    public function create(): void
    {
        Gate::authorize('featured.manage');
        $this->authorize('create', FeaturedProvider::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        Gate::authorize('featured.manage');
        $featured = FeaturedProvider::query()->findOrFail($id);
        $this->authorize('update', $featured);

        $this->resetForm();
        $this->editingId = $featured->getKey();
        $this->name_en = (string) $featured->name_en;
        $this->name_ar = (string) $featured->name_ar;
        $this->provider_type_id = $featured->provider_type_id !== null ? (string) $featured->provider_type_id : '';
        $this->sort_order = (string) $featured->sort_order;
        $this->is_active = (bool) $featured->is_active;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    public function save(AuditLogger $audit, FeaturedLogoStore $store): void
    {
        Gate::authorize('featured.manage');
        $existing = $this->editingId !== null ? FeaturedProvider::query()->findOrFail($this->editingId) : null;
        if ($existing !== null) {
            $this->authorize('update', $existing);
        } else {
            $this->authorize('create', FeaturedProvider::class);
        }

        $data = $this->validate($this->rules(), [], $this->attributeNames());

        $names = ['en' => trim((string) ($data['name_en'] ?? '')), 'ar' => trim((string) ($data['name_ar'] ?? ''))];
        if ($names['en'] === '' && $names['ar'] === '') {
            throw ValidationException::withMessages(['name_en' => __('ui.featured.name_required')]);
        }

        $oldPath = $existing?->logo_path;
        $newPath = $this->logo !== null ? $store->store($this->logo) : null; // re-validates the real image type

        try {
            $featured = DB::transaction(function () use ($existing, $data, $names, $newPath) {
                $featured = $existing ?? new FeaturedProvider;
                $featured->name_en = $names['en'] !== '' ? $names['en'] : null;
                $featured->name_ar = $names['ar'] !== '' ? $names['ar'] : null;
                $featured->provider_type_id = filled($data['provider_type_id'] ?? null) ? (int) $data['provider_type_id'] : null;
                $featured->sort_order = (int) $data['sort_order'];
                $featured->is_active = (bool) $data['is_active'];
                if ($newPath !== null) {
                    $featured->logo_path = $newPath;
                } elseif ($this->removeLogo) {
                    $featured->logo_path = null;
                }
                if ($existing === null) {
                    $featured->forceFill(['created_by' => auth()->id()]);
                }
                $featured->save();

                return $featured;
            });
        } catch (\Throwable $e) {
            $store->delete($newPath); // never leave an orphaned upload behind

            throw $e;
        }

        if ($oldPath !== null && ($newPath !== null || $this->removeLogo)) {
            $store->delete($oldPath);
        }

        $audit->record($existing ? 'featured.updated' : 'featured.created', $featured, 'success', ['active' => $featured->is_active]);

        $this->closeForm();
        $this->notify($existing ? __('ui.featured.updated') : __('ui.featured.created'));
    }

    public function confirmDelete(int $id): void
    {
        Gate::authorize('featured.manage');
        $featured = FeaturedProvider::query()->findOrFail($id);
        $this->authorize('delete', $featured);
        $this->deletingId = $featured->getKey();
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
    }

    public function performDelete(AuditLogger $audit, FeaturedLogoStore $store): void
    {
        Gate::authorize('featured.manage');
        $featured = FeaturedProvider::query()->findOrFail((int) $this->deletingId);
        $this->authorize('delete', $featured);

        $path = $featured->logo_path;
        $featured->delete();
        $store->delete($path);
        $audit->record('featured.deleted', ['featured_provider', $featured->getKey()]);

        $this->deletingId = null;
        $this->notify(__('ui.featured.deleted'));
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name_en', 'name_ar', 'provider_type_id', 'is_active', 'removeLogo', 'logo']);
        $this->sort_order = '0';
        $this->is_active = true;
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $this->authorize('viewAny', FeaturedProvider::class);

        $manage = Gate::allows('featured.manage');

        return view('livewire.featured.index', [
            'items' => FeaturedProvider::query()->with('type.translations')->orderBy('sort_order')->orderBy('id')->paginate(25),
            'types' => $manage ? ProviderType::query()->with('translations')->orderBy('sort_order')->orderBy('key')->get() : collect(),
            'manage' => $manage,
            'locale' => app()->getLocale(),
            'editing' => $this->editingId !== null ? FeaturedProvider::query()->find($this->editingId) : null,
            'deleting' => $this->deletingId !== null ? FeaturedProvider::query()->find($this->deletingId) : null,
            'maxKb' => (int) config('providers.featured.max_logo_kb', 1024),
        ])->title(__('ui.nav.featured'));
    }
}
