<?php

namespace App\Services\Legacy;

use App\Models\LegacyTypeMapping;

/**
 * Resolves a raw legacy type label through the versioned legacy_type_mappings table.
 * Nothing is ever guessed: an unknown label, an ambiguous label or a mapping still awaiting
 * owner review resolves to a non-approved state and the source row goes to the review queue.
 *
 * Lookup key = trimmed, case-folded raw value (the same equivalence the utf8mb4_unicode_ci
 * unique index applies, so MySQL and SQLite behave identically).
 */
class TypeMapper
{
    public const APPROVED = 'approved';

    public const NEEDS_REVIEW = 'needs_review';

    /**
     * Deterministic plural/synonym label whose mapping still awaits owner approval (D3). Resolved
     * only when the caller explicitly accepts provisional mappings (local rehearsal); otherwise it
     * behaves exactly like needs_review.
     */
    public const PROVISIONAL = 'provisional';

    public const UNMAPPED = 'unmapped';

    public const BLANK = 'blank';

    /** @var array<string, array{type: ?int, status: string}> */
    private array $map = [];

    public function __construct(public readonly string $version, public readonly bool $acceptProvisional = false)
    {
        foreach (LegacyTypeMapping::query()->where('mapping_version', $version)->orderBy('id')->get() as $row) {
            $key = self::key($row->raw_value);
            $entry = ['type' => $row->provider_type_id, 'status' => $row->status];

            if (isset($this->map[$key]) && $this->map[$key] !== $entry) {
                // Two mappings for the same (folded) label that disagree: ambiguous, never guess.
                $entry = ['type' => null, 'status' => self::NEEDS_REVIEW];
            }
            $this->map[$key] = $entry;
        }
    }

    /** @return array{type: ?int, status: string} */
    public function resolve(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return ['type' => null, 'status' => self::BLANK];
        }

        $entry = $this->map[self::key($raw)] ?? null;
        if ($entry === null) {
            return ['type' => null, 'status' => self::UNMAPPED];
        }

        if ($entry['status'] === self::APPROVED && $entry['type'] !== null) {
            return $entry;
        }
        if ($entry['status'] === self::PROVISIONAL && $entry['type'] !== null && $this->acceptProvisional) {
            return $entry;
        }

        return ['type' => null, 'status' => self::NEEDS_REVIEW];
    }

    public static function key(string $raw): string
    {
        return mb_strtolower(trim($raw));
    }
}
