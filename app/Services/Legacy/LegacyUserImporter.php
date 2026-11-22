<?php

namespace App\Services\Legacy;

use App\Enums\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Carries legitimate legacy staff identities into V2 (ADR-016). LOCAL-ONLY like every legacy step.
 *
 * - The identity is preserved: e-mail (normalized to lower case), name, e-mail verification date.
 * - Roles: legacy `super_admin`/`admin` → Admin; `editor` → Editor; `viewer` → Viewer. Any other role
 *   is HELD (not imported) and reported; nothing is guessed.
 * - An e-mail that already exists in V2 is never overwritten, merged or re-activated (skipped).
 * - Password policy:
 *     activation    (default) the legacy hash is NOT read; the account gets an unusable random password
 *                   and is activated with `users:set-password` (hidden prompt) or the password-reset e-mail.
 *     preserve-hash the legacy bcrypt hash is copied unchanged so the existing password keeps working;
 *                   allowed only for bcrypt hashes the V2 hasher verifies (algorithm + cost <= BCRYPT_ROUNDS).
 * Output and audit metadata never contain a hash, a password or a token.
 */
class LegacyUserImporter
{
    public const MODES = ['activation', 'preserve-hash'];

    private const ROLE_MAP = [
        'super_admin' => Role::Admin,
        'admin' => Role::Admin,
        'editor' => Role::Editor,
        'viewer' => Role::Viewer,
    ];

    public function __construct(private readonly LegacySource $source, private readonly AuditLogger $audit) {}

    /**
     * @return array{mode: string, dry_run: bool, accounts: int, created: int, skipped_existing: int, held: int,
     *               rows: list<array{legacy_id: int, email: string, legacy_role: string, v2_role: ?string, outcome: string}>}
     */
    public function import(string $mode, bool $dryRun): array
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new LegacyException('Unknown password policy; use activation or preserve-hash.');
        }
        LegacyGuard::assertDestinationSafe();

        $accounts = $this->source->accounts(withPasswordHash: $mode === 'preserve-hash');
        $result = ['mode' => $mode, 'dry_run' => $dryRun, 'accounts' => count($accounts), 'created' => 0, 'skipped_existing' => 0, 'held' => 0, 'rows' => []];

        DB::beginTransaction();
        try {
            foreach ($accounts as $row) {
                $email = mb_strtolower(trim((string) $row['email']));
                $legacyRole = (string) ($row['role'] ?? '');
                $role = self::ROLE_MAP[$legacyRole] ?? null;
                $outcome = $this->decide($email, $role, $mode, $row);

                if ($outcome === 'create') {
                    $user = new User(['name' => trim((string) $row['name']) ?: Str::before($email, '@'), 'email' => $email]);
                    $user->password = $mode === 'preserve-hash' ? (string) $row['password'] : Hash::make(Str::random(64));
                    $user->role = $role;
                    $user->is_active = true;
                    $user->email_verified_at = $row['email_verified_at'];
                    $user->save();
                    $this->audit->record('user.migrated_from_legacy', $user, 'success', [
                        'legacy_user_id' => (int) $row['id'], 'legacy_role' => $legacyRole, 'role' => $role?->value, 'access_policy' => $mode,
                    ], null);
                    $result['created']++;
                    $outcome = $mode === 'preserve-hash' ? 'created (legacy password kept)' : 'created (activation required)';
                } elseif ($outcome === 'exists') {
                    $result['skipped_existing']++;
                    $outcome = 'skipped: e-mail already exists in V2 (untouched)';
                } else {
                    $result['held']++;
                }

                $result['rows'][] = [
                    'legacy_id' => (int) $row['id'], 'email' => self::mask($email), 'legacy_role' => $legacyRole,
                    'v2_role' => $role?->value, 'outcome' => $outcome,
                ];
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $result;
    }

    /** @param array<string, mixed> $row */
    private function decide(string $email, ?Role $role, string $mode, array $row): string
    {
        if (Validator::make(['e' => $email], ['e' => ['required', 'email:rfc', 'max:255']])->fails()) {
            return 'held: invalid e-mail';
        }
        if ($role === null) {
            return 'held: unknown legacy role (decision needed)';
        }
        if (User::query()->where('email', $email)->exists()) {
            return 'exists';
        }
        if ($mode === 'preserve-hash' && ! $this->hashAcceptable((string) ($row['password'] ?? ''))) {
            return 'held: legacy hash not verifiable by V2 (use activation)';
        }

        return 'create';
    }

    /**
     * Accept a legacy hash only if it is bcrypt AND the User model's own `hashed` cast keeps it unchanged
     * (the cast refuses hashes whose algorithm or cost the configured hasher cannot verify).
     */
    private function hashAcceptable(string $hash): bool
    {
        if (! Hash::isHashed($hash) || Hash::info($hash)['algoName'] !== 'bcrypt') {
            return false;
        }
        try {
            $probe = new User;
            $probe->password = $hash;

            return $probe->password === $hash;
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).'***@'.$domain;
    }
}
