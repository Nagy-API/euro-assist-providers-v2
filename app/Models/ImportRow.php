<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRow extends Model
{
    public const ACTION_CREATE = 'create';

    public const ACTION_UPDATE = 'update';

    public const ACTION_UNCHANGED = 'unchanged';

    public const ACTION_ERROR = 'error';

    public const APPLY_PENDING = 'pending';

    public const APPLY_APPLIED = 'applied';

    public const APPLY_SKIPPED = 'skipped';

    public const APPLY_CONFLICT = 'conflict';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'diff' => 'array', 'errors' => 'array', 'applied_at' => 'datetime'];
    }

    /** @return BelongsTo<ImportJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class, 'import_job_id');
    }
}
