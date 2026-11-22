<?php

namespace App\Livewire;

use App\Services\DashboardStats;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Dashboard extends Component
{
    public function mount(): void
    {
        Gate::authorize('providers.view');
    }

    public function render(DashboardStats $stats): View
    {
        Gate::authorize('providers.view');

        // Soft-deleted records are visible only to roles that may restore them (Admin), like the list filter.
        $counters = $stats->counters(includeDeleted: Gate::allows('providers.restore'));

        return view('livewire.dashboard', [
            'counters' => $counters,
            'perType' => $stats->perType(app()->getLocale()),
            'recent' => $stats->recentlyUpdated(),
            'canEdit' => Gate::allows('providers.update'),
            'locale' => app()->getLocale(),
            // Legacy review backlog: Admin (migration.review) only, and only where the review route exists.
            'legacy' => Gate::allows('migration.review') && Route::has('migration.review.index') ? $stats->legacyBacklog() : null,
        ])->title(__('ui.nav.dashboard'));
    }
}
