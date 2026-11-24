<?php

namespace App\Livewire\Providers;

use App\Livewire\Concerns\HasNotices;
use App\Models\AuditEvent;
use App\Models\Provider;
use App\Models\User;
use App\Services\ProviderService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Show extends Component
{
    use HasNotices;

    /** Only the id is kept: a soft-deleted model cannot be re-hydrated by Livewire's model synthesizer. */
    #[Locked]
    public int $providerId;

    /** delete|restore|null */
    public ?string $dialog = null;

    public function mount(Provider $provider): void
    {
        $this->authorize('view', $provider);
        if ($provider->merged_into_id !== null) {
            // A merge shell has no content of its own: show the provider it was merged into.
            $this->providerId = (int) $provider->merged_into_id;
            $this->redirectRoute('providers.show', $provider->merged_into_id);

            return;
        }
        $this->guardTrashed($provider);
        $this->providerId = $provider->getKey();
    }

    public function confirmDelete(): void
    {
        Gate::authorize('providers.delete');
        $this->authorize('delete', $this->provider());
        $this->dialog = 'delete';
    }

    public function confirmRestore(): void
    {
        Gate::authorize('providers.restore');
        $this->authorize('restore', $this->provider());
        $this->dialog = 'restore';
    }

    public function closeDialog(): void
    {
        $this->dialog = null;
    }

    public function performDelete(ProviderService $service): void
    {
        Gate::authorize('providers.delete');
        $provider = $this->provider();
        $this->authorize('delete', $provider);
        $service->delete(auth()->user(), $provider);
        $this->dialog = null;
        $this->notify(__('ui.providers.deleted'));
    }

    public function performRestore(ProviderService $service): void
    {
        Gate::authorize('providers.restore');
        $provider = $this->provider();
        $this->authorize('restore', $provider);
        $service->restore(auth()->user(), $provider);
        $this->dialog = null;
        $this->notify(__('ui.providers.restored'));
    }

    private function provider(): Provider
    {
        $provider = Provider::withTrashed()->with(['translations', 'type.translations'])->findOrFail($this->providerId);
        $this->authorize('view', $provider);
        $this->guardTrashed($provider);

        return $provider;
    }

    /** Deleted providers are only visible to users who may restore them; everyone else gets a 404. */
    private function guardTrashed(Provider $provider): void
    {
        if ($provider->trashed() && ! Gate::allows('providers.restore')) {
            abort(404);
        }
    }

    public function render(): View
    {
        $provider = $this->provider();
        $locale = app()->getLocale();

        $names = User::query()
            ->whereIn('id', array_values(array_filter([$provider->created_by, $provider->updated_by])))
            ->pluck('name', 'id');

        $audit = Gate::allows('audit.view')
            ? AuditEvent::query()->with('actor')
                ->where('target_type', $provider->getMorphClass())->where('target_id', (string) $provider->getKey())
                ->orderByDesc('id')->limit(8)->get()
            : collect();

        // Current UI locale first, then the others: both translations are shown side by side.
        $locales = collect((array) config('providers.locales'))->sortBy(fn ($l) => $l === $locale ? 0 : 1)->values()->all();

        return view('livewire.providers.show', [
            'provider' => $provider,
            'locales' => $locales,
            'locale' => $locale,
            'createdBy' => $names->get($provider->created_by),
            'updatedBy' => $names->get($provider->updated_by),
            'audit' => $audit,
        ])->title($provider->displayTranslation($locale)->name ?? __('ui.providers.details'));
    }
}
