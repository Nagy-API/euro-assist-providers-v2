<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $payload
 */
class LegacyStagingRow extends Model
{
    public const STATE_STAGED = 'staged';

    public const STATE_MAPPED = 'mapped';

    public const STATE_NEEDS_REVIEW = 'needs_review';

    public const STATE_REJECTED = 'rejected';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
