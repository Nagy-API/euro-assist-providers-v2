<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\Provider;
use App\Models\ProviderTranslation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Single entry point for writing canonical providers (UI, bulk, import and API all use it).
 *
 * Input shape:
 *   ['provider_type_id' => int, 'phone' => ?string, 'email' => ?string, 'website' => ?string,
 *    'map_url' => ?string, 'latitude' => ?numeric, 'longitude' => ?numeric, 'logo_path' => ?string,
 *    'is_active' => ?bool,
 *    'translations' => ['en' => ['name' => ..., 'address' => ..., 'city' => ...], 'ar' => [...]]]
 *
 * ADR-008: on update, absent keys and blank values PRESERVE stored data. Clearing is a separate,
 * explicit operation ($clear) available only with the providers.clear_values permission (Admin).
 */
class ProviderService
{
    /** Canonical scalar columns that may be cleared. */
    public const CLEARABLE_COLUMNS = ['phone', 'email', 'website', 'map_url', 'latitude', 'longitude', 'logo_path'];

    /** Translation fields that may be cleared (name/address are required and can never be cleared). */
    public const CLEARABLE_TRANSLATION_FIELDS = ['city', 'district', 'specialization', 'hours', 'discount_text', 'package_text'];

    public function __construct(private readonly AuditLogger $audit) {}

    // ------------------------------------------------------------------ validation

    /**
     * Validate and normalize input. $creating requires a type and at least one complete
     * translation; updates validate only what was supplied.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $input, bool $creating, ?Provider $existing = null): array
    {
        $input = $this->normalize($input);
        $locales = config('providers.locales');

        $rules = [
            // On update a blank type preserves the stored one (ADR-008); create requires it.
            'provider_type_id' => [$creating ? 'required' : 'nullable', 'integer', Rule::exists('provider_types', 'id')],
            'phone' => ['nullable', 'string', 'max:500'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'website' => ['nullable', 'string', 'max:500', $this->safeUrlRule(allowBare: true)],
            'map_url' => ['nullable', 'string', 'max:2048', $this->safeUrlRule(allowBare: false)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'logo_path' => ['nullable', 'string', 'max:500', $this->safeLogoPathRule()],
            'is_active' => ['nullable', 'boolean'],
            'translations' => [$creating ? 'required' : 'sometimes', 'array'],
        ];
        foreach ($locales as $locale) {
            $rules["translations.$locale"] = ['sometimes', 'array'];
            foreach (ProviderTranslation::TEXT_FIELDS as $field) {
                $max = in_array($field, ['specialization', 'hours', 'discount_text', 'package_text'], true) ? 5000
                    : ($field === 'address' ? 500 : 255);
                $rules["translations.$locale.$field"] = ['nullable', 'string', "max:$max"];
            }
        }

        $validator = Validator::make($input, $rules);

        $validator->after(function ($v) use ($input, $creating, $existing, $locales) {
            $translations = $input['translations'] ?? [];

            foreach ($translations as $locale => $fields) {
                if (! in_array($locale, $locales, true)) {
                    $v->errors()->add("translations.$locale", 'Unsupported locale.');

                    continue;
                }
                if ($this->localeHasContent($fields)) {
                    $stored = $existing?->translation($locale);
                    foreach (ProviderTranslation::REQUIRED_FIELDS as $required) {
                        $given = $fields[$required] ?? null;
                        $hasStored = $stored && filled($stored->{$required});
                        // On update a blank required field preserves the stored value (ADR-008).
                        if (! filled($given) && ! ($existing && $hasStored)) {
                            $v->errors()->add("translations.$locale.$required", "The $required is required for the $locale translation.");
                        }
                    }
                }
            }

            if ($creating) {
                $complete = array_filter(
                    (array) $translations,
                    fn ($f, $l) => in_array($l, $locales, true) && filled($f['name'] ?? null) && filled($f['address'] ?? null),
                    ARRAY_FILTER_USE_BOTH
                );
                if ($complete === [] && ! $v->errors()->has('translations')) {
                    $v->errors()->add('translations', 'At least one complete translation (name and address) is required.');
                }
            }
        });

        $validated = $validator->validate();

        return $this->normalize(array_replace($input, array_intersect_key($validated, $input)));
    }

    /**
     * Trim strings; turn blank strings into null. Phones/text are never numerically coerced.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalize(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            if ($key === 'translations') {
                $out['translations'] = [];
                foreach ((array) $value as $locale => $fields) {
                    $out['translations'][strtolower((string) $locale)] = array_map(
                        fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v,
                        (array) $fields
                    );
                }

                continue;
            }
            $out[$key] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }

        return $out;
    }

    /**
     * Only http(s) links may be stored: `javascript:`/`data:`/`file:` values would become stored
     * XSS when rendered as hrefs. A bare host (website only) is allowed and kept as typed.
     */
    private function safeUrlRule(bool $allowBare): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($allowBare): void {
            $v = (string) $value;
            $ok = ! preg_match('/[\x00-\x20\x7F<>"\'\\\\]/u', $v) && (
                preg_match('#^https?://[^/\s]+#i', $v) === 1
                || ($allowBare && ! preg_match('#^[a-z][a-z0-9+.\-]*:(?!\d+(/|$))#i', $v) && preg_match('/^[^\s\/:]+\.[^\s\/:]+/u', $v) === 1)
            );
            if (! $ok) {
                $fail("The $attribute must be an http or https address.");
            }
        };
    }

    /** logo_path is a raw reference: an http(s) URL or a plain relative path, never traversal. */
    private function safeLogoPathRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $v = (string) $value;
            $isUrl = preg_match('#^https?://[^/\s]+[^\s<>"\'\\\\]*$#i', $v) === 1;
            $isRelative = preg_match('#^(?!/)(?!.*\.\.)[A-Za-z0-9_\-./]+$#', $v) === 1;
            if (! $isUrl && ! $isRelative) {
                $fail("The $attribute must be a safe relative path or an http(s) URL.");
            }
        };
    }

    /** @param array<string, mixed> $fields */
    private function localeHasContent(array $fields): bool
    {
        return collect($fields)->contains(fn ($v) => filled($v));
    }

    // ------------------------------------------------------------------ writes

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $actor, array $input): Provider
    {
        $this->authorize($actor, Permission::ProvidersCreate);
        $data = $this->validate($input, creating: true);

        $provider = DB::transaction(function () use ($actor, $data) {
            $provider = new Provider($this->scalarColumns($data));
            $provider->is_active = $data['is_active'] ?? true;
            $provider->revision = 1;
            $provider->created_by = $actor->getKey();
            $provider->updated_by = $actor->getKey();
            $provider->save();

            foreach ($data['translations'] ?? [] as $locale => $fields) {
                if (filled($fields['name'] ?? null) && filled($fields['address'] ?? null)) {
                    $provider->translations()->create(['locale' => $locale] + $this->translationColumns($fields));
                }
            }

            return $provider;
        });

        $this->audit->record('provider.created', $provider, 'success', [], $actor);

        return $provider->load('translations', 'type');
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $clear  e.g. ['phone', 'translations.ar.city'] — Admin only (ADR-008)
     */
    public function update(User $actor, Provider $provider, array $input, array $clear = []): Provider
    {
        $this->authorize($actor, Permission::ProvidersUpdate);
        if ($clear !== []) {
            $this->authorize($actor, Permission::ProvidersClearValues);
        }
        // Optional optimistic-concurrency guard: callers that loaded a form pass the revision they saw.
        $expectedRevision = isset($input['expected_revision']) ? (int) $input['expected_revision'] : null;
        unset($input['expected_revision']);
        $data = $this->validate($input, creating: false, existing: $provider->load('translations'));
        $clear = $this->validateClear($clear);

        $changed = DB::transaction(function () use ($actor, $provider, $data, $clear, $expectedRevision) {
            // Re-read under lock so revision checks and writes are serialized per provider.
            $locked = Provider::query()->withTrashed()->lockForUpdate()->findOrFail($provider->getKey());
            if ($locked->trashed()) {
                throw ValidationException::withMessages(['provider' => 'A deleted provider cannot be edited; restore it first.']);
            }
            if ($expectedRevision !== null && $expectedRevision !== (int) $locked->revision) {
                throw ValidationException::withMessages(['revision' => 'This provider was changed by someone else. Reload and try again.']);
            }
            $locked->load('translations');

            $changed = [];
            $scalars = $this->scalarColumns($data);
            foreach ($scalars as $column => $value) {
                if ($value === null) {
                    continue; // blank preserves (ADR-008)
                }
                if ((string) $locked->{$column} !== (string) $value) {
                    $locked->{$column} = $value;
                    $changed[] = $column;
                }
            }
            if (array_key_exists('is_active', $data) && $data['is_active'] !== null
                && (bool) $locked->is_active !== (bool) $data['is_active']) {
                $locked->is_active = (bool) $data['is_active'];
                $changed[] = 'is_active';
            }

            foreach ($clear as $path) {
                if (! str_contains($path, '.')) {
                    if ($locked->{$path} !== null) {
                        $locked->{$path} = null;
                        $changed[] = $path.' (cleared)';
                    }
                }
            }

            foreach ($data['translations'] ?? [] as $locale => $fields) {
                $existing = $locked->translation($locale);
                $cols = $this->translationColumns($fields);
                $present = array_filter($cols, fn ($v) => $v !== null);
                if ($existing === null) {
                    if ($present !== []) {
                        $locked->translations()->create(['locale' => $locale] + $cols);
                        $changed[] = "translations.$locale (created)";
                    }

                    continue;
                }
                $dirty = [];
                foreach ($present as $field => $value) {
                    if ((string) $existing->{$field} !== (string) $value) {
                        $dirty[$field] = $value;
                    }
                }
                if ($dirty !== []) {
                    $existing->fill($dirty)->save();
                    foreach (array_keys($dirty) as $f) {
                        $changed[] = "translations.$locale.$f";
                    }
                }
            }

            foreach ($clear as $path) {
                if (preg_match('/^translations\.([a-z]{2})\.([a-z_]+)$/', $path, $m)) {
                    $t = $locked->translation($m[1]);
                    if ($t && $t->{$m[2]} !== null) {
                        $t->{$m[2]} = null;
                        $t->save();
                        $changed[] = "translations.$m[1].$m[2] (cleared)";
                    }
                }
            }

            if ($changed !== []) {
                $locked->updated_by = $actor->getKey();
                $locked->revision = $locked->revision + 1;
                $locked->save();
            }

            return $changed;
        });

        $this->audit->record('provider.updated', $provider, 'success', ['changed_fields' => $changed], $actor);

        return $provider->refresh()->load('translations', 'type');
    }

    public function delete(User $actor, Provider $provider): void
    {
        $this->authorize($actor, Permission::ProvidersDelete);
        $provider->delete();
        $this->audit->record('provider.deleted', $provider, 'success', [], $actor);
    }

    public function restore(User $actor, Provider $provider): void
    {
        $this->authorize($actor, Permission::ProvidersRestore);
        $provider->restore();
        $this->audit->record('provider.restored', $provider, 'success', [], $actor);
    }

    /**
     * @param  list<int>  $ids
     */
    public function bulkDelete(User $actor, array $ids): int
    {
        $this->authorize($actor, Permission::ProvidersBulkDelete);
        $ids = $this->validatedIds($ids);
        $count = DB::transaction(fn () => Provider::query()->whereIn('id', $ids)->get()->each->delete()->count());
        $this->audit->record('provider.bulk_deleted', ['provider', null], 'success', ['count' => $count, 'ids' => array_slice($ids, 0, 50)], $actor);

        return $count;
    }

    /**
     * @param  list<int>  $ids
     */
    public function bulkRestore(User $actor, array $ids): int
    {
        $this->authorize($actor, Permission::ProvidersBulkDelete);
        $ids = $this->validatedIds($ids);
        $count = DB::transaction(fn () => Provider::onlyTrashed()->whereIn('id', $ids)->get()->each->restore()->count());
        $this->audit->record('provider.bulk_restored', ['provider', null], 'success', ['count' => $count, 'ids' => array_slice($ids, 0, 50)], $actor);

        return $count;
    }

    // ------------------------------------------------------------------ bulk write: preview -> confirm

    /**
     * Step 1 of a bulk update (validation + preview). Nothing is written. Returns a token the
     * actor must pass to applyBulk() to confirm.
     *
     * @param  list<int>  $ids
     * @param  array{provider_type_id?: int|null, is_active?: bool|null}  $changes
     * @return array{token: string, count: int, changes: array<string, mixed>, sample_ids: list<int>}
     */
    public function previewBulk(User $actor, array $ids, array $changes): array
    {
        $this->authorize($actor, Permission::ProvidersBulkWrite);

        $changes = array_filter(
            array_intersect_key($changes, array_flip(['provider_type_id', 'is_active'])),
            fn ($v) => filled($v) || $v === false
        );
        $ids = $this->validatedIds($ids);
        Validator::make($changes, [
            'provider_type_id' => ['sometimes', 'integer', Rule::exists('provider_types', 'id')],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();
        if ($changes === []) {
            throw ValidationException::withMessages(['changes' => 'Choose at least one value to change.']);
        }

        $existing = Provider::query()->whereIn('id', $ids)->pluck('id')->all();
        $token = bin2hex(random_bytes(16));
        cache()->put($this->bulkKey($actor, $token), ['ids' => $existing, 'changes' => $changes], now()->addMinutes(15));

        return ['token' => $token, 'count' => count($existing), 'changes' => $changes, 'sample_ids' => array_slice($existing, 0, 10)];
    }

    /**
     * Step 2: apply a previewed, confirmed bulk update. A token is single-use, actor-bound and
     * expires; without a valid token nothing is written.
     *
     * @return int number of providers updated
     */
    public function applyBulk(User $actor, string $token): int
    {
        $this->authorize($actor, Permission::ProvidersBulkWrite);
        // Atomic single-use: Cache::add succeeds for exactly one caller, even under a race.
        $claimed = cache()->add($this->bulkKey($actor, $token).':used', 1, now()->addMinutes(15));
        $plan = $claimed ? cache()->pull($this->bulkKey($actor, $token)) : null;
        if (! is_array($plan)) {
            throw ValidationException::withMessages(['token' => 'The bulk update was not previewed and confirmed, or it expired.']);
        }

        $count = DB::transaction(function () use ($actor, $plan) {
            $n = 0;
            foreach (array_chunk($plan['ids'], 200) as $chunk) {
                $n += Provider::query()->whereIn('id', $chunk)->update($plan['changes'] + [
                    'updated_by' => $actor->getKey(),
                    'updated_at' => now(),
                    'revision' => DB::raw('revision + 1'),
                ]);
            }

            return $n;
        });

        $this->audit->record('provider.bulk_updated', ['provider', null], 'success',
            ['count' => $count, 'changes' => $plan['changes'], 'ids' => array_slice($plan['ids'], 0, 50)], $actor);

        return $count;
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<int>
     */
    private function validatedIds(array $ids): array
    {
        if ($ids === [] || count($ids) > 1000) {
            throw ValidationException::withMessages(['ids' => 'Select between 1 and 1000 providers.']);
        }
        foreach ($ids as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw ValidationException::withMessages(['ids' => 'Provider ids must be integers.']);
            }
        }

        return array_values(array_map('intval', $ids));
    }

    private function bulkKey(User $actor, string $token): string
    {
        return 'bulk-provider-update:'.$actor->getKey().':'.$token;
    }

    // ------------------------------------------------------------------ helpers

    private function authorize(User $actor, Permission $permission): void
    {
        if (! Gate::forUser($actor)->allows($permission->value)) {
            $this->audit->record('authorization.denied', null, 'denied', ['permission' => $permission->value], $actor);
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    /**
     * @param  list<string>  $clear
     * @return list<string>
     */
    private function validateClear(array $clear): array
    {
        foreach ($clear as $path) {
            $ok = in_array($path, self::CLEARABLE_COLUMNS, true)
                || (preg_match('/^translations\.([a-z]{2})\.([a-z_]+)$/', $path, $m)
                    && in_array($m[1], config('providers.locales'), true)
                    && in_array($m[2], self::CLEARABLE_TRANSLATION_FIELDS, true));
            if (! $ok) {
                throw ValidationException::withMessages(['clear' => "Value cannot be cleared: $path"]);
            }
        }

        return array_values(array_unique($clear));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function scalarColumns(array $data): array
    {
        return array_intersect_key($data, array_flip(['provider_type_id', ...self::CLEARABLE_COLUMNS]));
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function translationColumns(array $fields): array
    {
        return array_intersect_key($fields, array_flip(ProviderTranslation::TEXT_FIELDS));
    }
}
