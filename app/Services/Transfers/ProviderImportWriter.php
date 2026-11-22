<?php

namespace App\Services\Transfers;

use App\Models\Provider;
use App\Models\ProviderTranslation;
use App\Models\User;
use LogicException;

/**
 * Bulk-friendly provider writer for the import apply step. It applies exactly the rules of
 * ProviderService (ADR-008 blank-preserves; revision bump once per changed provider; created_by /
 * updated_by = the confirming actor) but WITHOUT a per-row audit event or per-row validation
 * query: the payload was fully validated at staging time, the caller holds the provider row lock
 * inside the chunk transaction, and the job writes ONE `import.applied` audit event.
 *
 * It never clears anything: only non-null staged values are written.
 */
class ProviderImportWriter
{
    /** @param array<string, mixed> $payload staged payload (see ImportValidator::payload()) */
    public function create(User $actor, array $payload): Provider
    {
        $translation = (array) ($payload['translation'] ?? []);
        if (! filled($translation['name'] ?? null) || ! filled($translation['address'] ?? null) || ! isset($payload['provider_type_id'], $payload['locale'])) {
            throw new LogicException('Staged create row is incomplete.');
        }

        $provider = new Provider(['provider_type_id' => (int) $payload['provider_type_id']] + $this->scalars($payload));
        $provider->is_active = (bool) ($payload['is_active'] ?? true);
        $provider->revision = 1;
        $provider->created_by = $actor->getKey();
        $provider->updated_by = $actor->getKey();
        $provider->save();

        $provider->translations()->create(['locale' => $payload['locale']] + $this->translationColumns($translation));

        return $provider;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return bool whether anything changed (the revision is bumped exactly once when it did)
     */
    public function update(User $actor, Provider $provider, array $payload): bool
    {
        $changed = false;

        foreach ($this->scalars($payload) as $column => $value) {
            if ((string) $provider->{$column} !== (string) $value) {
                $provider->{$column} = $value;
                $changed = true;
            }
        }
        if (isset($payload['provider_type_id']) && (int) $provider->provider_type_id !== (int) $payload['provider_type_id']) {
            $provider->provider_type_id = (int) $payload['provider_type_id'];
            $changed = true;
        }
        if (array_key_exists('is_active', $payload) && $payload['is_active'] !== null
            && (bool) $provider->is_active !== (bool) $payload['is_active']) {
            $provider->is_active = (bool) $payload['is_active'];
            $changed = true;
        }

        $translation = (array) ($payload['translation'] ?? []);
        if ($translation !== [] && isset($payload['locale'])) {
            $existing = $provider->translation((string) $payload['locale']);
            if ($existing === null) {
                if (! filled($translation['name'] ?? null) || ! filled($translation['address'] ?? null)) {
                    throw new LogicException('Staged translation create is incomplete.');
                }
                $created = $provider->translations()->create(['locale' => $payload['locale']] + $this->translationColumns($translation));
                $provider->translations->push($created);
                $changed = true;
            } else {
                $dirty = [];
                foreach ($this->translationColumns($translation) as $field => $value) {
                    if ((string) $existing->{$field} !== (string) $value) {
                        $dirty[$field] = $value;
                    }
                }
                if ($dirty !== []) {
                    $existing->fill($dirty)->save();
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $provider->updated_by = $actor->getKey();
            $provider->revision = $provider->revision + 1;
            $provider->save();
        }

        return $changed;
    }

    /**
     * Only the template's scalar columns can ever be written (never logo_path or any other column).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function scalars(array $payload): array
    {
        return array_intersect_key((array) ($payload['scalars'] ?? []), array_flip(TemplateSchema::SCALAR_FIELDS));
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
