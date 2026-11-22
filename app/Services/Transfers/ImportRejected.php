<?php

namespace App\Services\Transfers;

use RuntimeException;

/**
 * A whole-file rejection (not a row error): the file cannot be accepted or parsed at all.
 * The code maps to lang/*\/transfers.php under "errors.file.<code>".
 */
class ImportRejected extends RuntimeException
{
    /** @param array<string, scalar> $params */
    public function __construct(public readonly string $errorCode, public readonly array $params = [])
    {
        parent::__construct($errorCode);
    }
}
