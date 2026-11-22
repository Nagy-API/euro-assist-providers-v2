<?php

namespace App\Services\Legacy;

use App\Models\LegacyMigrationBatch;

/** Finds a migration batch by uuid or numeric id (the value accepted by --batch / {batch}). */
final class BatchLocator
{
    public static function find(string $ref): LegacyMigrationBatch
    {
        $query = LegacyMigrationBatch::query();
        $batch = ctype_digit($ref) ? $query->find((int) $ref) : $query->where('uuid', $ref)->first();

        if ($batch === null) {
            throw new LegacyException('No such migration batch.');
        }

        return $batch;
    }

    /** The most recent batch that is neither rolled back nor failed, or null. */
    public static function latestActive(): ?LegacyMigrationBatch
    {
        return LegacyMigrationBatch::query()->whereIn('status', ['staged', 'applied'])->orderByDesc('id')->first();
    }
}
