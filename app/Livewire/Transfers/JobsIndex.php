<?php

namespace App\Livewire\Transfers;

use App\Enums\Permission;
use App\Models\ImportJob;
use App\Models\User;
use App\Services\Transfers\JobAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/** Combined import + export history. Admin (jobs.view.any) sees everyone's jobs; others only their own. */
#[Layout('components.layouts.app')]
class JobsIndex extends Component
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
        $all = $user->hasPermission(Permission::JobsViewAny);

        $imports = DB::table('import_jobs')->select([
            'uuid', 'user_id', 'status', 'created_at', 'total_rows as rows',
            DB::raw("'import' as kind"), 'mode as detail', 'original_filename as label',
        ]);
        $exports = DB::table('export_jobs')->select([
            'uuid', 'user_id', 'status', 'created_at', 'written_rows as rows',
            DB::raw("'export' as kind"), 'locale as detail', 'scope as label',
        ]);
        if (! $all) {
            $imports->where('user_id', $user->getKey());
            $exports->where('user_id', $user->getKey());
        }

        $jobs = DB::query()->fromSub($imports->unionAll($exports), 'history')
            ->orderByDesc('created_at')->orderByDesc('uuid')
            ->paginate(20);

        $names = User::query()->whereIn('id', $jobs->getCollection()->pluck('user_id')->unique())->pluck('name', 'id');

        return view('livewire.transfers.jobs-index', [
            'jobs' => $jobs,
            'names' => $names,
            'all' => $all,
        ])->title(__('transfers.jobs.title'));
    }
}
