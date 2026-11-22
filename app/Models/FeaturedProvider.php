<?php

namespace App\Models;

use Database\Factories\FeaturedProviderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Brand-level presentation record; deliberately not linked to Provider rows (provisional D5). */
class FeaturedProvider extends Model
{
    /** @use HasFactory<FeaturedProviderFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name_en', 'name_ar', 'provider_type_id', 'logo_path', 'sort_order', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<ProviderType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ProviderType::class, 'provider_type_id');
    }

    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $primary = $locale === 'ar' ? $this->name_ar : $this->name_en;

        return (string) ($primary ?: ($this->name_en ?: $this->name_ar));
    }
}
