<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderTranslation extends Model
{
    /** Columns carrying localized free text, in the order used by forms and exports. */
    public const TEXT_FIELDS = [
        'name', 'address', 'city', 'district', 'specialization', 'hours', 'discount_text', 'package_text',
    ];

    /** Required whenever a translation exists. */
    public const REQUIRED_FIELDS = ['name', 'address'];

    /** @var list<string> */
    protected $fillable = ['provider_id', 'locale', ...self::TEXT_FIELDS];

    /** @return BelongsTo<Provider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
