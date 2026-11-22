<?php

namespace App\Services\Transfers;

use RuntimeException;

/** A staged row no longer matches the provider (base_revision mismatch, provider removed). */
class ImportConflict extends RuntimeException
{
    public function __construct(public readonly int $importRowId, public readonly int $rowNumber)
    {
        parent::__construct('conflict');
    }
}
