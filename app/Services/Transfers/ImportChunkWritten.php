<?php

namespace App\Services\Transfers;

use App\Models\ImportJob;

/**
 * Dispatched INSIDE the chunk transaction after the chunk's provider writes and before the rows are
 * marked applied / the transaction commits. Listeners that throw simulate a mid-chunk crash:
 * the whole chunk rolls back and earlier chunks stay committed. Used by the resumability tests.
 */
final class ImportChunkWritten
{
    public function __construct(public readonly ImportJob $job, public readonly int $chunkIndex, public readonly int $rows) {}
}
