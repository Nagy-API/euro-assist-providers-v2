<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ImportJob extends Model
{
    public const MODE_CREATE_ONLY = 'create_only';

    public const MODE_UPDATE_ONLY = 'update_only';

    public const MODE_UPSERT = 'upsert';

    public const MODE_DRY_RUN = 'dry_run';

    public const MODES = [self::MODE_CREATE_ONLY, self::MODE_UPDATE_ONLY, self::MODE_UPSERT, self::MODE_DRY_RUN];

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_VALIDATION_FAILED = 'validation_failed';

    public const STATUS_READY = 'ready';

    public const STATUS_APPLYING = 'applying';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_INTERRUPTED = 'interrupted';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** Statuses from which no further processing can happen. */
    public const TERMINAL = [
        self::STATUS_VALIDATION_FAILED, self::STATUS_COMPLETED, self::STATUS_CONFLICT,
        self::STATUS_FAILED, self::STATUS_CANCELLED,
    ];

    /** @var list<string> */
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (ImportJob $job): void {
            $job->uuid ??= (string) Str::uuid();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'error_summary' => 'array',
            'confirmed_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
