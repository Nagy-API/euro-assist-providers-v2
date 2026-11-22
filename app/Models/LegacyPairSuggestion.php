<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed>|null $signals
 * @property Carbon|null $reviewed_at
 */
class LegacyPairSuggestion extends Model
{
    public const STATUS_SUGGESTED = 'suggested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['signals' => 'array', 'reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<LegacyIdMap, $this> */
    public function left(): BelongsTo
    {
        return $this->belongsTo(LegacyIdMap::class, 'left_map_id');
    }

    /** @return BelongsTo<LegacyIdMap, $this> */
    public function right(): BelongsTo
    {
        return $this->belongsTo(LegacyIdMap::class, 'right_map_id');
    }
}
