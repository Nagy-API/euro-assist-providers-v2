<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes minimal, safe audit events. Raw provider records, passwords, tokens and spreadsheet
 * contents must never be passed in metadata; sensitive-looking keys are dropped defensively.
 */
class AuditLogger
{
    private const FORBIDDEN_KEY_FRAGMENTS = ['password', 'token', 'secret', 'cookie', 'authorization', 'api_key'];

    /** Metadata values longer than this are truncated so a record can never be dumped into the log. */
    private const MAX_VALUE_LENGTH = 200;

    /**
     * @param  Model|array{0: string, 1: int|string|null}|null  $target  model or [type, id]
     * @param  array<string, scalar|array<array-key, scalar|null>|null>  $metadata
     */
    public function record(
        string $action,
        Model|array|null $target = null,
        string $result = 'success',
        array $metadata = [],
        ?User $actor = null,
    ): AuditEvent {
        $actor ??= auth()->user();

        [$type, $id] = match (true) {
            $target instanceof Model => [$target->getMorphClass(), (string) $target->getKey()],
            is_array($target) => [$target[0], isset($target[1]) ? (string) $target[1] : null],
            default => [null, null],
        };

        return AuditEvent::query()->create([
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'target_type' => $type,
            'target_id' => $id,
            'result' => $result,
            'metadata' => $this->sanitize($metadata) ?: null,
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     * @return array<array-key, mixed>
     */
    private function sanitize(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            $lower = strtolower((string) $key);
            foreach (self::FORBIDDEN_KEY_FRAGMENTS as $fragment) {
                if (str_contains($lower, $fragment)) {
                    continue 2;
                }
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
            } elseif (is_string($value)) {
                $clean[$key] = mb_strlen($value) > self::MAX_VALUE_LENGTH
                    ? mb_substr($value, 0, self::MAX_VALUE_LENGTH).'…'
                    : $value;
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
