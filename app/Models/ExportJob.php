<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property array<string, mixed>|null $filters
 * @property list<int>|null $selected_ids
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $expires_at
 */
class ExportJob extends Model
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_FILTERED = 'filtered';

    public const SCOPE_SELECTED = 'selected';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    /** @var list<string> */
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (ExportJob $job): void {
            $job->uuid ??= (string) Str::uuid();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'filters' => 'array', 'selected_ids' => 'array', 'started_at' => 'datetime',
            'finished_at' => 'datetime', 'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
