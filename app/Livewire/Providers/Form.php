<?php

namespace App\Livewire\Providers;

use App\Livewire\Concerns\HasNotices;
use App\Models\Provider;
use App\Models\ProviderTranslation;
use App\Models\ProviderType;
use App\Services\ProviderService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One canonical form for create and edit; Arabic and English translation sections live in the same
 * form (never duplicated CRUD per language). All writes go through ProviderService.
 *
 * ADR-008: on edit a blank field PRESERVES the stored value. Only users holding
 * providers.clear_values (Admin) can explicitly clear a value, through the `clear` list, which the
 * component and the service both re-authorize (the property is client-writable, so it is never trusted).
 */
#[Layout('components.layouts.app')]
class Form extends Component
{
    use HasNotices;

    private const SCALARS = ['phone', 'email', 'website', 'map_url', 'latitude', 'longitude'];

    #[Locked]
    public ?int $providerId = null;

    /** Revision loaded into the form; a save is refused if the stored revision has moved on. */
    #[Locked]
    public int $revision = 0;

    public string $provider_type_id = '';

    public string $phone = '';

    public string $email = '';

    public string $website = '';

    public string $map_url = '';

    public string $latitude = '';

    public string $longitude = '';

    public bool $is_active = true;

    /** @var array<string, array<string, string>> locale => field => value */
    public array $translations = [];

    /** @var list<string> paths to clear explicitly, e.g. ['phone', 'translations.ar.city'] (Admin only) */
    public array $clear = [];

    public string $tab = 'ar';

    #[Locked]
    public bool $conflict = false;

    public function mount(?Provider $provider = null): void
    {
        $this->tab = in_array(app()->getLocale(), config('providers.locales'), true) ? app()->getLocale() : 'en';

        if ($provider !== null && $provider->exists) {
            $this->authorize('update', $provider);
            $this->providerId = $provider->getKey();
            $this->loadFrom($this->currentProvider()); // always the stored state, including its revision
        } else {
            $this->authorize('create', Provider::class);
            $this->providerId = null;
            foreach (config('providers.locales') as $locale) {
                foreach (ProviderTranslation::TEXT_FIELDS as $field) {
                    $this->translations[$locale][$field] = '';
                }
            }
        }
    }

    private function loadFrom(Provider $provider): void
    {
        $this->revision = (int) $provider->revision;
        $this->provider_type_id = (string) $provider->provider_type_id;
        foreach (self::SCALARS as $field) {
            $this->{$field} = (string) ($provider->{$field} ?? '');
        }
        $this->is_active = (bool) $provider->is_active;
        $this->translations = [];
        foreach (config('providers.locales') as $locale) {
            $translation = $provider->translation($locale);
            foreach (ProviderTranslation::TEXT_FIELDS as $field) {
                $this->translations[$locale][$field] = (string) ($translation->{$field} ?? ''); // ?? also covers a missing translation
            }
        }
        $this->clear = [];
        $this->conflict = false;
    }

    /** Discard unsaved input and load the latest stored version (after an edit conflict). */
    public function reload(): void
    {
        $provider = $this->currentProvider();
        $this->authorize('update', $provider);
        $this->resetErrorBag();
        $this->loadFrom($provider);
        $this->notify(__('ui.form.reloaded'), 'info');
    }

    public function save(ProviderService $service): void
    {
        $editing = $this->providerId !== null;

        if ($editing) {
            $this->authorize('update', $this->currentProvider());
        } else {
            $this->authorize('create', Provider::class);
        }

        $clear = $editing ? $this->clearPaths() : [];
        if ($clear !== []) {
            // `clear` is a client-writable property: only users with providers.clear_values may use it.
            $this->authorize('clearValues', Provider::class);
        }

        $payload = $this->payload($clear);
        $actor = auth()->user();

        try {
            if ($editing) {
                $provider = DB::transaction(function () use ($service, $actor, $payload, $clear) {
                    $stored = Provider::query()->lockForUpdate()->findOrFail($this->providerId);
                    if ((int) $stored->revision !== $this->revision) {
                        return null; // someone else saved first
                    }

                    return $service->update($actor, $stored, $payload, $clear);
                });

                if ($provider === null) {
                    $this->conflict = true;
                    $this->resetErrorBag();

                    return;
                }
                session()->flash('status', __('ui.form.saved'));
            } else {
                $provider = $service->create($actor, $payload);
                session()->flash('status', __('ui.form.created'));
            }
        } catch (ValidationException $e) {
            $this->tab = $this->firstErrorLocale($e->errors()) ?? $this->tab;
            throw $e;
        }

        $this->redirectRoute('providers.show', $provider);
    }

    /** @return list<string> */
    private function clearPaths(): array
    {
        return array_values(array_unique(array_filter($this->clear, 'is_string')));
    }

    /**
     * Input for ProviderService. Blank values are omitted (the service also treats blanks as
     * "preserve"); values marked for clearing are omitted too so a clear never competes with input.
     *
     * @param  list<string>  $clear
     * @return array<string, mixed>
     */
    private function payload(array $clear): array
    {
        $payload = [];

        if (trim($this->provider_type_id) !== '') {
            $payload['provider_type_id'] = ctype_digit(trim($this->provider_type_id)) ? (int) $this->provider_type_id : $this->provider_type_id;
        }
        foreach (self::SCALARS as $field) {
            $value = trim((string) $this->{$field});
            if ($value !== '' && ! in_array($field, $clear, true)) {
                $payload[$field] = $value;
            }
        }
        $payload['is_active'] = $this->is_active;

        foreach (config('providers.locales') as $locale) {
            $fields = [];
            foreach (ProviderTranslation::TEXT_FIELDS as $field) {
                $value = $this->translations[$locale][$field] ?? '';
                $path = "translations.$locale.$field";
                $fields[$field] = trim($value) !== '' && ! in_array($path, $clear, true) ? $value : null;
            }
            $payload['translations'][$locale] = $fields;
        }

        return $payload;
    }

    /** @param array<string, list<string>> $errors */
    private function firstErrorLocale(array $errors): ?string
    {
        foreach (array_keys($errors) as $key) {
            if (preg_match('/^translations\.([a-z]{2})\b/', $key, $m) && in_array($m[1], config('providers.locales'), true)) {
                return $m[1];
            }
        }

        return null;
    }

    private function currentProvider(): Provider
    {
        return Provider::query()->with('translations')->findOrFail((int) $this->providerId);
    }

    public function render(): View
    {
        $editing = $this->providerId !== null;
        $stored = $editing ? $this->currentProvider() : null;
        $canClear = $editing && Gate::allows('providers.clear_values');

        $types = ProviderType::query()->with('translations')
            ->where(function ($q) use ($stored) {
                $q->where('is_active', true);
                if ($stored) {
                    $q->orWhere('id', $stored->provider_type_id);
                }
            })
            ->orderBy('sort_order')->orderBy('key')->get();

        $storedFlags = [];
        if ($stored && $canClear) {
            foreach (['phone', 'email', 'website', 'map_url', 'latitude', 'longitude'] as $f) {
                $storedFlags[$f] = filled($stored->{$f});
            }
            foreach (config('providers.locales') as $locale) {
                foreach (ProviderService::CLEARABLE_TRANSLATION_FIELDS as $f) {
                    $storedFlags["translations.$locale.$f"] = filled($stored->translation($locale)?->{$f});
                }
            }
        }

        $errorLocales = [];
        foreach (config('providers.locales') as $locale) {
            $errorLocales[$locale] = collect((array) $this->getErrorBag()->keys())->contains(fn ($k) => str_starts_with($k, "translations.$locale."));
        }

        return view('livewire.providers.form', [
            'editing' => $editing,
            'canClear' => $canClear,
            'storedFlags' => $storedFlags,
            'types' => $types,
            'locales' => config('providers.locales'),
            'errorLocales' => $errorLocales,
            'uiLocale' => app()->getLocale(),
        ])->title($editing ? __('ui.form.edit_title') : __('ui.form.create_title'));
    }
}
