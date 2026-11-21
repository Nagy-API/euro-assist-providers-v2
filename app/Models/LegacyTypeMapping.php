<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegacyTypeMapping extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return BelongsTo<ProviderType, $this> */
    public function providerType(): BelongsTo
    {
        return $this->belongsTo(ProviderType::class);
    }
}
