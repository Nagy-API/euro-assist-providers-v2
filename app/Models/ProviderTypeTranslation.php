<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderTypeTranslation extends Model
{
    /** @var list<string> */
    protected $fillable = ['provider_type_id', 'locale', 'name'];

    /** @return BelongsTo<ProviderType, $this> */
    public function providerType(): BelongsTo
    {
        return $this->belongsTo(ProviderType::class);
    }
}
