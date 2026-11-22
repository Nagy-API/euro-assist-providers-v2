<?php

namespace App\Livewire\Transfers;

use App\Models\ExportJob;
use App\Models\ProviderType;
use App\Services\Transfers\JobAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/** Export request form (CSRF POST) + the current user's own export history. */
#[Layout('components.layouts.app')]
class ExportsIndex extends Component
{
    use WithPagination;

    public function mount(): void
    {
        JobAccess::viewAny(auth()->user(), ExportJob::class);
    }

    public function render(): View
    {
        $user = auth()->user();
        JobAccess::viewAny($user, ExportJob::class);

        $jobs = ExportJob::query()
            ->where('user_id', $user->getKey())
            ->latest('id')
            ->paginate(10);

        $locale = app()->getLocale();
        $types = ProviderType::query()->with('translations')->orderBy('sort_order')->get()
            ->map(fn (ProviderType $t) => ['id' => $t->id, 'label' => $t->label($locale)])
            ->all();

        return view('livewire.transfers.exports-index', [
            'jobs' => $jobs,
            'canExport' => $user->can('create', ExportJob::class),
            'types' => $types,
            'isAdmin' => $user->isAdmin(),
            'selectedMax' => (int) config('providers.exports.selected_max'),
        ])->title(__('transfers.exports.title'));
    }
}
