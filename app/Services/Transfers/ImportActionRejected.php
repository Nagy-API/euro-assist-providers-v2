<?php

namespace App\Services\Transfers;

use RuntimeException;

/** A confirm/cancel/retry request that is not allowed in the job's current state. Nothing changed. */
class ImportActionRejected extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }

    /** Localized message (lang/*\/transfers.php: actions.<code>). */
    public function userMessage(): string
    {
        return (string) __("transfers.actions.{$this->errorCode}");
    }
}
