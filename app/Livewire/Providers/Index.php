<?php

namespace App\Livewire\Providers;

use App\Livewire\Concerns\HasNotices;
use App\Models\Provider;
use App\Models\ProviderTranslation;
use App\Models\ProviderType;
use App\Services\ProviderService;
use App\Services\Transfers\ExportService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use LogicException;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use HasNotices, WithPagination;

    /** Sort keys the UI may request; anything else falls back to the default. */
    public const SORTS = ['name', 'type', 'city', 'updated_at', 'id'];

    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $city = '';

    #[Url(except: '')]
    public string $district = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $trashed = '';

    #[Url(except: 'updated_at')]
    public string $sort = 'updated_at';

    #[Url(except: 'desc')]
    public string $dir = 'desc';

    #[Url(except: '')]
    public string $perPage = '';

    /** @var array<int|string, mixed> ids ticked on the current page (client supplied: re-validated server-side) */
    public array $selected = [];

    public bool $selectPage = false;

    /** delete|restore|bulk|bulk_delete|bulk_restore, or null when no dialog is open. */
    public ?string $dialog = null;

    #[Locked]
    public ?int $targetId = null;

    public string $bulkType = '';

    public string $bulkActive = '';

    #[Locked]
    public ?string $bulkToken = null;

    /** @var array{count: int, type: string|null, active: string|null}|null */
    #[Locked]
    public ?array $bulkPreview = null;

    public function paginationView(): string
    {
        return 'pagination.ui';
    }

    public function mount(): void
    {
        $this->authorize('viewAny', Provider::class);
        $this->normalize();
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['search', 'type', 'city', 'district', 'status', 'trashed', 'perPage'], true)) {
            $this->resetPage();
            $this->clearSelection();
        }
    }

    public function updatedSelectPage(bool $value): void
    {
        $this->authorize('viewAny', Provider::class);
        $this->selected = $value ? $this->pageIds() : [];
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, self::SORTS, true)) {
            return;
        }
        if ($this->sort === $column) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->dir = in_array($column, ['updated_at', 'id'], true) ? 'desc' : 'asc';
        }
        $this->resetPage();
        $this->clearSelection();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'type', 'city', 'district', 'status', 'trashed']);
        $this->resetPage();
        $this->clearSelection();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->selectPage = false;
    }

    // ------------------------------------------------------------------ per-row actions (Admin)

    public function confirmDelete(int $id): void
    {
        Gate::authorize('providers.delete');
        $this->authorize('delete', Provider::query()->findOrFail($id));
        $this->targetId = $id;
        $this->dialog = 'delete';
    }

    public function confirmRestore(int $id): void
    {
        Gate::authorize('providers.restore');
        $this->authorize('restore', Provider::onlyTrashed()->findOrFail($id));
        $this->targetId = $id;
        $this->dialog = 'restore';
    }

    public function performDelete(ProviderService $service): void
    {
        Gate::authorize('providers.delete');
        $provider = Provider::query()->findOrFail((int) $this->targetId);
        $this->authorize('delete', $provider);

        $service->delete(auth()->user(), $provider);

        $this->closeDialog();
        $this->forgetSelected([$provider->getKey()]);
        $this->notify(__('ui.providers.deleted'));
    }

    public function performRestore(ProviderService $service): void
    {
        Gate::authorize('providers.restore');
        $provider = Provider::onlyTrashed()->findOrFail((int) $this->targetId);
        $this->authorize('restore', $provider);

        $service->restore(auth()->user(), $provider);

        $this->closeDialog();
        $this->forgetSelected([$provider->getKey()]);
        $this->notify(__('ui.providers.restored'));
    }

    // ------------------------------------------------------------------ bulk actions

    public function openBulk(): void
    {
        $this->authorize('bulkWrite', Provider::class);
        if ($this->selectedIds() === []) {
            $this->notify(__('ui.providers.nothing_selected'), 'error');

            return;
        }
        $this->reset(['bulkType', 'bulkActive', 'bulkToken', 'bulkPreview']);
        $this->resetErrorBag();
        $this->dialog = 'bulk';
    }

    /** Step 1: validate and preview. Nothing is written until the user confirms (applyBulk). */
    public function previewBulk(ProviderService $service): void
    {
        $this->authorize('bulkWrite', Provider::class);

        $changes = [];
        if ($this->bulkType !== '') {
            $changes['provider_type_id'] = (int) $this->bulkType;
        }
        if ($this->bulkActive !== '') {
            $changes['is_active'] = $this->bulkActive === '1';
        }

        try {
            $plan = $service->previewBulk(auth()->user(), $this->selectedIds(), $changes);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->resetErrorBag();
        $this->bulkToken = $plan['token'];
        $this->bulkPreview = [
            'count' => $plan['count'],
            'type' => isset($plan['changes']['provider_type_id'])
                ? ProviderType::query()->with('translations')->find($plan['changes']['provider_type_id'])?->label()
                : null,
            'active' => array_key_exists('is_active', $plan['changes'])
                ? ($plan['changes']['is_active'] ? 'active' : 'inactive')
                : null,
        ];
    }

    public function backToBulkForm(): void
    {
        $this->authorize('bulkWrite', Provider::class);
        $this->bulkToken = null;
        $this->bulkPreview = null;
    }

    /** Step 2: apply exactly the previewed plan (single-use, actor-bound token). */
    public function applyBulk(ProviderService $service): void
    {
        $this->authorize('bulkWrite', Provider::class);

        try {
            $count = $service->applyBulk(auth()->user(), (string) $this->bulkToken);
        } catch (ValidationException $e) {
            $this->closeDialog();
            $this->notify(collect($e->errors())->flatten()->first() ?? __('ui.common.error_generic'), 'error');

            return;
        }

        $this->closeDialog();
        $this->clearSelection();
        $this->notify(__('ui.providers.bulk_applied', ['count' => $count]));
    }

    public function openBulkDelete(): void
    {
        $this->authorize('bulkDelete', Provider::class);
        if ($this->selectedIds() === []) {
            $this->notify(__('ui.providers.nothing_selected'), 'error');

            return;
        }
        $this->dialog = 'bulk_delete';
    }

    public function openBulkRestore(): void
    {
        $this->authorize('bulkDelete', Provider::class);
        if ($this->selectedIds() === []) {
            $this->notify(__('ui.providers.nothing_selected'), 'error');

            return;
        }
        $this->dialog = 'bulk_restore';
    }

    public function performBulkDelete(ProviderService $service): void
    {
        $this->authorize('bulkDelete', Provider::class);
        $count = $service->bulkDelete(auth()->user(), $this->selectedIds());

        $this->closeDialog();
        $this->clearSelection();
        $this->notify(__('ui.providers.bulk_deleted', ['count' => $count]));
    }

    public function performBulkRestore(ProviderService $service): void
    {
        $this->authorize('bulkDelete', Provider::class);
        $count = $service->bulkRestore(auth()->user(), $this->selectedIds());

        $this->closeDialog();
        $this->clearSelection();
        $this->notify(__('ui.providers.bulk_restored', ['count' => $count]));
    }

    public function closeDialog(): void
    {
        $this->dialog = null;
        $this->targetId = null;
        $this->bulkToken = null;
        $this->bulkPreview = null;
        $this->resetErrorBag();
    }

    // ------------------------------------------------------------------ export (delegated to ExportService)

    public function exportFiltered(): void
    {
        $this->dispatchExport('filtered', []);
    }

    public function exportSelected(): void
    {
        $this->dispatchExport('selected', $this->selectedIds());
    }

    /**
     * @param  'filtered'|'selected'  $scope
     * @param  list<int>  $ids
     */
    private function dispatchExport(string $scope, array $ids): void
    {
        Gate::authorize('exports.run');

        if ($scope === 'selected' && $ids === []) {
            $this->notify(__('ui.providers.nothing_selected'), 'error');

            return;
        }

        try {
            $job = app(ExportService::class)->dispatch(auth()->user(), $scope, app()->getLocale(), $this->exportFilters(), $ids);
        } catch (LogicException) {
            // The export pipeline is delivered by a separate work stream; degrade gracefully.
            $this->notify(__('ui.providers.export_unavailable'), 'info');

            return;
        }

        $this->notify(__('ui.providers.export_queued', ['id' => $job->getKey()])
            .(Route::has('jobs.index') ? ' '.__('ui.providers.export_see_history') : ''));
    }

    /** @return array<string, string> */
    private function exportFilters(): array
    {
        return array_filter([
            'search' => $this->searchTerm(),
            'type' => $this->typeId() !== null ? (string) $this->typeId() : '',
            'city' => trim($this->city),
            'district' => trim($this->district),
            'status' => $this->statusValue(),
            'trashed' => $this->trashedValue(),
        ], fn ($v) => $v !== '');
    }

    // ------------------------------------------------------------------ querying

    private function searchTerm(): string
    {
        return mb_substr(trim($this->search), 0, 100);
    }

    private function typeId(): ?int
    {
        return ctype_digit($this->type) ? (int) $this->type : null; // ctype_digit('') is false
    }

    private function statusValue(): string
    {
        return in_array($this->status, ['active', 'inactive'], true) ? $this->status : '';
    }

    /** Only users who may restore (Admin) can see deleted providers; anyone else is silently ignored. */
    private function trashedValue(): string
    {
        return Gate::allows('providers.restore') && in_array($this->trashed, ['with', 'only'], true) ? $this->trashed : '';
    }

    private function sortValue(): string
    {
        return in_array($this->sort, self::SORTS, true) ? $this->sort : 'updated_at';
    }

    private function dirValue(): string
    {
        return $this->dir === 'asc' ? 'asc' : 'desc';
    }

    private function perPageValue(): int
    {
        $max = (int) config('providers.pagination.max', 100);
        $allowed = array_values(array_filter(self::PER_PAGE_OPTIONS, fn (int $n) => $n <= $max));
        $requested = (int) $this->perPage;

        return in_array($requested, $allowed, true) ? $requested : min((int) config('providers.pagination.default', 25), $max);
    }

    /** Resets URL-supplied state that is not valid, so the UI always reflects what is applied. */
    private function normalize(): void
    {
        $this->sort = $this->sortValue();
        $this->dir = $this->dirValue();
        $this->status = $this->statusValue();
        $this->trashed = $this->trashedValue();
        $this->type = $this->typeId() !== null ? (string) $this->typeId() : '';
        $this->perPage = (string) $this->perPageValue();
    }

    /** @return Builder<Provider> */
    private function query(): Builder
    {
        $locale = app()->getLocale();
        $other = $locale === 'ar' ? 'en' : 'ar';
        $sort = $this->sortValue();
        $dir = $this->dirValue();

        $query = Provider::query()->select('providers.*')->with(['translations', 'type.translations']);

        match ($this->trashedValue()) {
            // Merge shells of approved bilingual pairs are never listed as deleted providers (ADR-017).
            'with' => $query->withTrashed()->whereNull('providers.merged_into_id'),
            'only' => $query->onlyTrashed()->whereNull('providers.merged_into_id'),
            default => null,
        };

        $query->search($this->searchTerm())
            ->ofType($this->typeId())
            // Exact match in ANY translation: migrated records are often single-language (unpaired),
            // so an Arabic city must find Arabic-only providers from the English interface too.
            ->whereTranslated('city', $this->city)
            ->whereTranslated('district', $this->district);

        match ($this->statusValue()) {
            'active' => $query->where('providers.is_active', true),
            'inactive' => $query->where('providers.is_active', false),
            default => null,
        };

        // Sorting uses whitelisted column names only (never interpolates request values).
        if (in_array($sort, ['name', 'city'], true)) {
            $query->leftJoin('provider_translations as tl', fn ($j) => $j->on('tl.provider_id', '=', 'providers.id')->where('tl.locale', '=', $locale))
                ->leftJoin('provider_translations as tx', fn ($j) => $j->on('tx.provider_id', '=', 'providers.id')->where('tx.locale', '=', $other))
                ->orderByRaw("(coalesce(tl.{$sort}, tx.{$sort}) is null) asc")
                ->orderByRaw("coalesce(tl.{$sort}, tx.{$sort}) {$dir}");
        } elseif ($sort === 'type') {
            $query->leftJoin('provider_type_translations as ttl', fn ($j) => $j->on('ttl.provider_type_id', '=', 'providers.provider_type_id')->where('ttl.locale', '=', $locale))
                ->leftJoin('provider_type_translations as ttx', fn ($j) => $j->on('ttx.provider_type_id', '=', 'providers.provider_type_id')->where('ttx.locale', '=', $other))
                ->orderByRaw("coalesce(ttl.name, ttx.name) {$dir}");
        } else {
            $query->orderBy('providers.'.$sort, $dir);
        }

        return $query->orderBy('providers.id', $dir);
    }

    /** @return list<int> */
    private function pageIds(): array
    {
        return $this->query()->forPage($this->getPage(), $this->perPageValue())
            ->pluck('providers.id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> */
    private function selectedIds(): array
    {
        $ids = collect($this->selected)
            ->filter(fn ($v) => is_int($v) || (is_string($v) && ctype_digit($v)))
            ->map(fn ($v) => (int) $v)
            ->unique()->take(self::PER_PAGE_OPTIONS[count(self::PER_PAGE_OPTIONS) - 1])->values();

        return $ids->all();
    }

    /** @param list<int> $ids */
    private function forgetSelected(array $ids): void
    {
        $this->selected = array_values(array_filter($this->selected, fn ($v) => ! in_array((int) $v, $ids, true)));
    }

    /**
     * Distinct city/district values for the display locale (suggestions only; bounded).
     *
     * @return list<string>
     */
    private function suggestions(string $column): array
    {
        // Values of both languages, current interface language first (single-language records exist).
        return ProviderTranslation::query()
            ->whereNotNull($column)->where($column, '<>', '')
            ->distinct()->orderBy($column)->limit(600)->pluck($column)->all();
    }

    public function render(): View
    {
        $this->authorize('viewAny', Provider::class);

        $providers = $this->query()->paginate($this->perPageValue());

        return view('livewire.providers.index', [
            'providers' => $providers,
            'types' => ProviderType::query()->with('translations')->orderBy('sort_order')->orderBy('key')->get(),
            'cities' => $this->suggestions('city'),
            'districts' => $this->suggestions('district'),
            'locale' => app()->getLocale(),
            'sortCurrent' => $this->sortValue(),
            'dirCurrent' => $this->dirValue(),
            'canSeeTrashed' => Gate::allows('providers.restore'),
            'hasFilters' => $this->searchTerm() !== '' || $this->typeId() !== null || trim($this->city) !== '' || trim($this->district) !== ''
                || $this->statusValue() !== '' || $this->trashedValue() !== '',
            'perPageOptions' => array_values(array_filter(self::PER_PAGE_OPTIONS, fn (int $n) => $n <= (int) config('providers.pagination.max', 100))),
            'perPageCurrent' => $this->perPageValue(),
        ])->title(__('ui.providers.title'));
    }
}
