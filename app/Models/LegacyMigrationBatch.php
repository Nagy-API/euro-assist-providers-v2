<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LegacyMigrationBatch extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (LegacyMigrationBatch $batch): void {
            $batch->uuid ??= (string) Str::uuid();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['counts' => 'array', 'is_rehearsal' => 'boolean', 'applied_at' => 'datetime', 'rolled_back_at' => 'datetime'];
    }

    /** @return HasMany<LegacyStagingRow, $this> */
    public function stagingRows(): HasMany
    {
        return $this->hasMany(LegacyStagingRow::class, 'migration_batch_id');
    }

    /** @return HasMany<LegacyIdMap, $this> */
    public function idMaps(): HasMany
    {
        return $this->hasMany(LegacyIdMap::class, 'migration_batch_id');
    }
}
