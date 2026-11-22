<?php

namespace App\Services\Transfers;

/** Mutable bookkeeping for one validation run. Holds counters and small per-provider maps only. */
final class ValidationState
{
    public int $total = 0;

    public int $creates = 0;

    public int $updates = 0;

    public int $unchanged = 0;

    public int $errorRows = 0;

    /** @var array<string, int> error code => occurrences */
    public array $errorCodes = [];

    /** @var array<string, array{count: int, rows: list<int>}> */
    public array $warnings = [];

    /** @var array<string, int> "providerId|locale" => first row number */
    public array $seen = [];

    /** @var array<string, int> create-identity hash => first row number */
    public array $createSeen = [];

    /**
     * Revision bumps and scalar values already planned for a provider by earlier rows of this file
     * (a provider may legitimately appear once per locale).
     *
     * @var array<int, array{bumps: int, values: array<string, mixed>}>
     */
    public array $planned = [];

    /** @var list<string> */
    public array $unknownColumns = [];

    public const MAX_WARNING_ROWS = 20;

    public function warn(string $code, ?int $row = null): void
    {
        $this->warnings[$code] ??= ['count' => 0, 'rows' => []];
        $this->warnings[$code]['count']++;
        if ($row !== null && count($this->warnings[$code]['rows']) < self::MAX_WARNING_ROWS) {
            $this->warnings[$code]['rows'][] = $row;
        }
    }
}
