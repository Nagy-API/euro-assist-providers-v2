<?php

namespace App\Models;

use App\Support\Like;
use Database\Factories\ProviderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Canonical provider = one distinct branch/service location (ADR-009).
 * Language-dependent text lives in ProviderTranslation.
 */
class Provider extends Model
{
    /** @use HasFactory<ProviderFactory> */
    use HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'provider_type_id', 'phone', 'email', 'website', 'map_url', 'latitude', 'longitude',
        'logo_path', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'revision' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /** @return BelongsTo<ProviderType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ProviderType::class, 'provider_type_id');
    }

    /** @return HasMany<ProviderTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(ProviderTranslation::class);
    }

    /** Translation for a locale, or null. Never fabricates a translation. */
    public function translation(string $locale): ?ProviderTranslation
    {
        return $this->translations->firstWhere('locale', $locale);
    }

    /**
     * Translation for display: requested locale, then the default locale, then any.
     * The UI marks fallbacks explicitly; the returned value is never invented text.
     */
    public function displayTranslation(?string $locale = null): ?ProviderTranslation
    {
        $locale ??= app()->getLocale();

        return $this->translation($locale)
            ?? $this->translation((string) config('providers.default_locale'))
            ?? $this->translations->first();
    }

    /**
     * Substring search over the translated text of one locale (or all). Wildcard characters in
     * the term are escaped so they match literally; the term is always a bound parameter.
     *
     * @param  Builder<Provider>  $query
     */
    public function scopeSearch(Builder $query, ?string $term, ?string $locale = null): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }

        $query->where(function (Builder $q) use ($term, $locale) {
            $q->whereHas('translations', function (Builder $t) use ($term, $locale) {
                if ($locale) {
                    $t->where('locale', $locale);
                }
                Like::anyOf($t, ['name', 'address', 'city', 'district', 'specialization'], $term);
            })->orWhere(function (Builder $p) use ($term) {
                Like::anyOf($p, ['providers.phone', 'providers.email'], $term);
            });
        });
    }

    /** @param Builder<Provider> $query */
    public function scopeOfType(Builder $query, int|string|null $typeId): void
    {
        if ($typeId !== null && $typeId !== '') {
            $query->where('provider_type_id', (int) $typeId);
        }
    }

    /**
     * Exact-match translation filter (city/district), locale-scoped.
     *
     * @param  Builder<Provider>  $query
     */
    public function scopeWhereTranslated(Builder $query, string $column, ?string $value, ?string $locale = null): void
    {
        if (! in_array($column, ['city', 'district', 'name'], true) || $value === null || trim($value) === '') {
            return;
        }

        $query->whereHas('translations', function (Builder $t) use ($column, $value, $locale) {
            if ($locale) {
                $t->where('locale', $locale);
            }
            $t->where($column, trim($value));
        });
    }
}
