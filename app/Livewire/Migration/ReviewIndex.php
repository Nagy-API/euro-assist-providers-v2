<?php

namespace App\Livewire\Migration;

use App\Enums\Permission;
use App\Livewire\Concerns\HasNotices;
use App\Models\LegacyPairSuggestion;
use App\Models\User;
use App\Services\Legacy\LegacyException;
use App\Services\Legacy\PairReviewService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin-only review queue for suggested English/Arabic provider pairs (permission
 * migration.review). Real provider data is shown here to authorized admins only and is never
 * written to logs or audit metadata. Every action re-authorizes itself: Livewire update requests
 * do not pass through the route middleware.
 */
#[Layout('components.layouts.app')]
class ReviewIndex extends Component
{
    use HasNotices, WithPagination;

    public const STATUSES = ['suggested', 'approved', 'rejected'];

    #[Url(except: 'suggested')]
    public string $status = 'suggested';

    public function mount(): void
    {
        $this->authorizeReview();
        $this->normalizeStatus();
    }

    public function updatedStatus(): void
    {
        $this->authorizeReview();
        $this->normalizeStatus();
        $this->resetPage();
    }

    public function approve(int $id, PairReviewService $service): void
    {
        $this->run($id, fn (User $user, LegacyPairSuggestion $s) => $service->approve($user, $s), 'approved');
    }

    public function reject(int $id, PairReviewService $service): void
    {
        $this->run($id, fn (User $user, LegacyPairSuggestion $s) => $service->reject($user, $s), 'rejected');
    }

    public function undo(int $id, PairReviewService $service): void
    {
        $this->run($id, fn (User $user, LegacyPairSuggestion $s) => $service->undo($user, $s), 'undone');
    }

    /** @param callable(User, LegacyPairSuggestion): void $action */
    private function run(int $id, callable $action, string $outcome): void
    {
        $this->authorizeReview();
        /** @var User $user */
        $user = auth()->user();
        $suggestion = LegacyPairSuggestion::query()->findOrFail($id);

        try {
            $action($user, $suggestion);
            $this->notify(__('migration.notices.'.$outcome));
        } catch (LegacyException) {
            // Refusal reasons are fixed service messages; show a generic, translated notice.
            $this->notify(__('migration.notices.refused'), 'error');
        }
    }

    private function authorizeReview(): void
    {
        Gate::authorize(Permission::MigrationReview->value);
    }

    private function normalizeStatus(): void
    {
        if (! in_array($this->status, self::STATUSES, true)) {
            $this->status = 'suggested';
        }
    }

    public function render(): View
    {
        $this->authorizeReview();
        $locale = app()->getLocale();

        $suggestions = LegacyPairSuggestion::query()
            ->with([
                'left.provider' => fn ($q) => $q->withTrashed(),
                'left.provider.translations',
                'left.provider.type.translations',
                'right.provider' => fn ($q) => $q->withTrashed(),
                'right.provider.translations',
                'right.provider.type.translations',
            ])
            ->where('status', $this->status)
            ->orderByDesc('signal_count')->orderBy('id')
            ->paginate(10);

        return view('livewire.migration.review-index', [
            'suggestions' => $suggestions,
            'locale' => $locale,
            'counts' => LegacyPairSuggestion::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all(),
        ])->title(__('migration.title'));
    }
}
