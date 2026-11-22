<?php

namespace App\Livewire\Migration;

use App\Enums\Permission;
use App\Livewire\Concerns\HasNotices;
use App\Models\LegacyPairSuggestion;
use App\Models\LegacyStagingRow;
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

    /** @var array<int|string, mixed> suggestion id => reviewer's reason (required for every decision) */
    public array $reasons = [];

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
        $this->run($id, fn (User $user, LegacyPairSuggestion $s) => $service->approve($user, $s, $this->reasonFor($id)), 'approved');
    }

    public function reject(int $id, PairReviewService $service): void
    {
        $this->run($id, fn (User $user, LegacyPairSuggestion $s) => $service->reject($user, $s, $this->reasonFor($id)), 'rejected');
    }

    public function undo(int $id, PairReviewService $service): void
    {
        $this->run($id, fn (User $user, LegacyPairSuggestion $s) => $service->undo($user, $s, $this->reasonFor($id)), 'undone');
    }

    private function reasonFor(int $id): string
    {
        $value = $this->reasons[$id] ?? '';

        return is_string($value) ? $value : '';
    }

    /** @param callable(User, LegacyPairSuggestion): void $action */
    private function run(int $id, callable $action, string $outcome): void
    {
        $this->authorizeReview();
        $this->resetErrorBag('reasons.'.$id);
        $reason = trim($this->reasonFor($id));
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            $this->addError('reasons.'.$id, __('migration.reason_required'));

            return;
        }
        /** @var User $user */
        $user = auth()->user();
        $suggestion = LegacyPairSuggestion::query()->findOrFail($id);

        try {
            $action($user, $suggestion);
            unset($this->reasons[$id]);
            $this->notify(__('migration.notices.'.$outcome));
        } catch (LegacyException) {
            // Refusal reasons are fixed service messages; show a generic, translated notice.
            $this->notify(__('migration.notices.refused'), 'error');
        }
    }

    private function authorizeReview(): void
    {
        abort_unless(in_array(app()->environment(), (array) config('legacy.allowed_environments', []), true) && ! app()->environment('production'), 404);
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
            // Source rows not turned into canonical records: visible so nothing is silently left behind.
            'held' => LegacyStagingRow::query()->whereIn('state', [LegacyStagingRow::STATE_NEEDS_REVIEW, LegacyStagingRow::STATE_REJECTED])
                ->orderBy('source_table')->orderBy('source_id')->limit(500)
                ->get(['source_table', 'source_id', 'source_locale', 'state', 'reason']),
            'provisionalCount' => LegacyStagingRow::query()->where('state', LegacyStagingRow::STATE_MAPPED)->where('reason', 'provisional_type')->count(),
        ])->title(__('migration.title'));
    }
}
