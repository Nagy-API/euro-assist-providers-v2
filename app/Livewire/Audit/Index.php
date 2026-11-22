<?php

namespace App\Livewire\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Read-only global audit log (Admin, audit.view). Filters are whitelisted/validated and results are paginated. */
#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    public const RESULTS = ['success', 'denied', 'failure'];

    #[Url(except: '')]
    public string $action = '';

    #[Url(except: '')]
    public string $actor = '';

    #[Url(except: '')]
    public string $result = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function paginationView(): string
    {
        return 'pagination.ui';
    }

    public function mount(): void
    {
        $this->authorize('viewAny', AuditEvent::class);
        if ($this->parseDate($this->from) === null) {
            $this->from = '';
        }
        if ($this->parseDate($this->to) === null) {
            $this->to = '';
        }
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['action', 'actor', 'result', 'from', 'to'], true)) {
            $this->authorize('viewAny', AuditEvent::class);
            $this->resetPage();
        }
        if (in_array($name, ['from', 'to'], true)) {
            $this->validateOnly($name, [
                'from' => ['nullable', 'date_format:Y-m-d'],
                'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            ], [], ['from' => __('ui.audit.from'), 'to' => __('ui.audit.to')]);
        }
    }

    public function clearFilters(): void
    {
        $this->authorize('viewAny', AuditEvent::class);
        $this->reset(['action', 'actor', 'result', 'from', 'to']);
        $this->resetErrorBag();
        $this->resetPage();
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value)?->startOfDay();
    }

    /** @return Builder<AuditEvent> */
    private function query(): Builder
    {
        $query = AuditEvent::query()->with('actor');

        if ($this->action !== '') {
            $query->where('action', mb_substr($this->action, 0, 100));
        }
        if (ctype_digit($this->actor)) { // ctype_digit('') is false
            $query->where('actor_id', (int) $this->actor);
        }
        if (in_array($this->result, self::RESULTS, true)) {
            $query->where('result', $this->result);
        }
        $from = $this->parseDate($this->from);
        $to = $this->parseDate($this->to);
        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }
        if ($to !== null && ($from === null || $to >= $from)) {
            $query->where('created_at', '<=', $to->endOfDay());
        }

        return $query->orderByDesc('id');
    }

    public function render(): View
    {
        $this->authorize('viewAny', AuditEvent::class);

        return view('livewire.audit.index', [
            'events' => $this->query()->paginate(50),
            'actions' => AuditEvent::query()->select('action')->distinct()->orderBy('action')->limit(200)->pluck('action'),
            'actors' => User::query()->orderBy('name')->limit(300)->get(['id', 'name', 'email']),
            'results' => self::RESULTS,
            'hasFilters' => $this->action !== '' || $this->actor !== '' || $this->result !== '' || $this->from !== '' || $this->to !== '',
        ])->title(__('ui.nav.audit'));
    }
}
