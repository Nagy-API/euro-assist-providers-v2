<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One owner decision that overrides a single normalized value of a staged legacy row (ADR-017).
 *
 * @property int $migration_batch_id
 * @property string $source_table
 * @property int $source_id
 * @property string $field
 * @property string $value
 * @property string|null $original_sha256
 * @property string $reason
 * @property string|null $manifest_sha256
 * @property int|null $decided_by
 */
class LegacyRowDecision extends Model
{
    public const FIELD_TYPE_KEY = 'type_key';

    public const FIELD_MAP_URL = 'map_url';

    /** @var list<string> */
    protected $guarded = ['id'];
}
