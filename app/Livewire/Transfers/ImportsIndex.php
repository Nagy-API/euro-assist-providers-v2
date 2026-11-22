<?php

namespace App\Livewire\Transfers;

use App\Models\ImportJob;
use App\Services\Transfers\JobAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/** Upload form + the current user's own import history (read-only; uploading is a CSRF POST). */
#[Layout('components.layouts.app')]
class ImportsIndex extends Component
{
    use WithPagination;

    public function mount(): void
    {
        JobAccess::viewAny(auth()->user(), ImportJob::class);
    }

    public function render(): View
    {
        $user = auth()->user();
        JobAccess::viewAny($user, ImportJob::class);

        $jobs = ImportJob::query()
            ->where('user_id', $user->getKey())
            ->latest('id')
            ->paginate(10);

        return view('livewire.transfers.imports-index', [
            'jobs' => $jobs,
            'canUpload' => $user->can('create', ImportJob::class),
            'modes' => ImportJob::MODES,
            'maxMb' => round(config('providers.imports.max_file_bytes') / 1048576, 1),
            'maxRows' => (int) config('providers.imports.max_rows'),
        ])->title(__('transfers.imports.title'));
    }
}
