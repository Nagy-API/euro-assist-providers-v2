<?php

namespace App\Livewire\Types;

use App\Livewire\Concerns\HasNotices;
use App\Models\FeaturedProvider;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Provider type management (Admin, types.manage). Types are never deleted (policy forbids it):
 * deactivate them instead. The key becomes immutable once any provider or featured record uses it.
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    use HasNotices, WithPagination;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $key = '';

    public string $name_en = '';

    public string $name_ar = '';

    public string $sort_order = '0';

    public bool $is_active = true;

    public function paginationView(): string
    {
        return 'pagination.ui';
    }

    public function mount(): void
    {
        $this->authorize('viewAny', ProviderType::class);
        $this->authorize('create', ProviderType::class); // this page is the management screen
    }

    public function create(): void
    {
        Gate::authorize('types.manage');
        $this->authorize('create', ProviderType::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        Gate::authorize('types.manage');
        $type = ProviderType::query()->with('translations')->findOrFail($id);
        $this->authorize('update', $type);

        $this->resetForm();
        $this->editingId = $type->getKey();
        $this->key = $type->key;
        $this->name_en = (string) ($type->translations->firstWhere('locale', 'en')->name ?? '');
        $this->name_ar = (string) ($type->translations->firstWhere('locale', 'ar')->name ?? '');
        $this->sort_order = (string) $type->sort_order;
        $this->is_active = (bool) $type->is_active;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    public function save(AuditLogger $audit): void
    {
        Gate::authorize('types.manage');
        $existing = $this->editingId !== null ? ProviderType::query()->findOrFail($this->editingId) : null;
        if ($existing !== null) {
            $this->authorize('update', $existing);
        } else {
            $this->authorize('create', ProviderType::class);
        }

        $inUse = $existing !== null && $this->isInUse($existing);

        $data = $this->validate([
            'key' => [
                'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('provider_types', 'key')->ignore($existing?->getKey()),
            ],
            'name_en' => ['nullable', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['boolean'],
        ], attributes: [
            'key' => __('ui.types.key'), 'name_en' => __('ui.types.name_en'), 'name_ar' => __('ui.types.name_ar'),
            'sort_order' => __('ui.types.sort_order'),
        ]);

        $names = ['en' => trim($data['name_en'] ?? ''), 'ar' => trim($data['name_ar'] ?? '')];
        if ($names['en'] === '' && $names['ar'] === '') {
            throw ValidationException::withMessages(['name_en' => __('ui.types.name_required')]);
        }
        if ($inUse && $data['key'] !== $existing->key) {
            throw ValidationException::withMessages(['key' => __('ui.types.key_immutable')]);
        }

        $type = DB::transaction(function () use ($existing, $inUse, $data, $names) {
            $type = $existing ?? new ProviderType;
            if ($existing === null || ! $inUse) {
                $type->key = $data['key']; // a key already in use is never rewritten (rejected above)
            }
            $type->sort_order = (int) $data['sort_order'];
            $type->is_active = (bool) $data['is_active'];
            $type->save();

            foreach ($names as $locale => $name) {
                if ($name !== '') {
                    $type->translations()->updateOrCreate(['locale' => $locale], ['name' => $name]);
                } else {
                    $type->translations()->where('locale', $locale)->delete();
                }
            }

            return $type;
        });

        $audit->record($existing ? 'type.updated' : 'type.created', $type, 'success', ['key' => $type->key, 'active' => $type->is_active]);

        $this->closeForm();
        $this->notify($existing ? __('ui.types.updated') : __('ui.types.created'));
    }

    private function isInUse(ProviderType $type): bool
    {
        return Provider::withTrashed()->where('provider_type_id', $type->getKey())->exists()
            || FeaturedProvider::query()->where('provider_type_id', $type->getKey())->exists();
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'key', 'name_en', 'name_ar', 'is_active']);
        $this->sort_order = '0';
        $this->is_active = true;
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $this->authorize('viewAny', ProviderType::class);
        $this->authorize('create', ProviderType::class);

        $types = ProviderType::query()
            ->with('translations')
            ->withCount([
                'providers',
                'providers as providers_with_deleted_count' => fn ($q) => $q->withTrashed(),
            ])
            ->orderBy('sort_order')->orderBy('key')
            ->paginate(50);

        $editing = $this->editingId !== null ? ProviderType::query()->find($this->editingId) : null;

        return view('livewire.types.index', [
            'types' => $types,
            'locale' => app()->getLocale(),
            'keyLocked' => $editing !== null && $this->isInUse($editing),
        ])->title(__('ui.nav.types'));
    }
}
