<?php

namespace App\Models;

use Database\Factories\ProviderTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderType extends Model
{
    /** @use HasFactory<ProviderTypeFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['key', 'is_active', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return HasMany<ProviderTypeTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(ProviderTypeTranslation::class);
    }

    /** @return HasMany<Provider, $this> */
    public function providers(): HasMany
    {
        return $this->hasMany(Provider::class);
    }

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $fallback = config('providers.default_locale');

        $translations = $this->relationLoaded('translations')
            ? $this->translations->keyBy('locale')
            : $this->translations()->get()->keyBy('locale');

        $name = $translations->get($locale)->name
            ?? $translations->get($fallback)->name
            ?? $translations->first()->name
            ?? null;

        return $name ?? $this->key;
    }
}
