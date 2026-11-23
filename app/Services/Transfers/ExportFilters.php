<?php

namespace App\Services\Transfers;

use App\Models\Provider;
use Illuminate\Database\Eloquent\Builder;

/**
 * The provider-list filter keys (search, type, city, district, status, trashed) as export filters.
 * sanitize() runs at request time (unknown keys dropped, `trashed` honoured only for Admin);
 * apply() runs at generation time against the Provider query.
 */
final class ExportFilters
{
    public const KEYS = ['search', 'type', 'city', 'district', 'status', 'trashed'];

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, scalar>
     */
    public static function sanitize(array $filters, bool $isAdmin): array
    {
        $clean = [];
        foreach (['search', 'city', 'district'] as $key) {
            $value = $filters[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $clean[$key] = mb_substr(trim($value), 0, 255);
            }
        }
        $type = $filters['type'] ?? null;
        if ((is_int($type) || (is_string($type) && ctype_digit($type))) && (int) $type > 0) {
            $clean['type'] = (int) $type;
        }
        $status = $filters['status'] ?? null;
        if (in_array($status, ['active', 'inactive'], true)) {
            $clean['status'] = $status;
        }
        $trashed = $filters['trashed'] ?? null;
        if ($isAdmin && in_array($trashed, ['with', 'only'], true)) {
            $clean['trashed'] = $trashed;
        }

        return $clean;
    }

    /**
     * @param  Builder<Provider>  $query
     * @param  array<string, mixed>  $filters
     */
    public static function apply(Builder $query, array $filters, bool $isAdmin): void
    {
        if (isset($filters['search'])) {
            $query->search((string) $filters['search']);
        }
        if (isset($filters['type'])) {
            $query->ofType((int) $filters['type']);
        }
        if (isset($filters['city'])) {
            $query->whereTranslated('city', (string) $filters['city']);
        }
        if (isset($filters['district'])) {
            $query->whereTranslated('district', (string) $filters['district']);
        }
        if (($filters['status'] ?? null) === 'active') {
            $query->where('is_active', true);
        } elseif (($filters['status'] ?? null) === 'inactive') {
            $query->where('is_active', false);
        }
        if ($isAdmin) {
            if (($filters['trashed'] ?? null) === 'with') {
                $query->withTrashed()->whereNull('merged_into_id');
            } elseif (($filters['trashed'] ?? null) === 'only') {
                $query->onlyTrashed()->whereNull('merged_into_id');
            }
        }
    }
}
