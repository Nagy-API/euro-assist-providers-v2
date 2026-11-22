<?php

namespace App\Services\Transfers;

use RuntimeException;

/**
 * Raised when apply is attempted for a job that is not a valid, validated, confirmed import.
 * Nothing has been written when this is thrown.
 */
class ImportNotApplicable extends RuntimeException
{
    /** @param bool $failJob true when a confirmed, applying job can never proceed and must be marked failed */
    public function __construct(public readonly string $reason, public readonly bool $failJob = false)
    {
        parent::__construct($reason);
    }
}
