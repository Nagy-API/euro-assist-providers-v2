<?php

namespace App\Console\Commands;

use App\Services\Legacy\LegacyUserImporter;

/**
 * Carries legacy staff identities into V2 (ADR-016). Local-only like every legacy:* command.
 * Prints masked e-mails and outcomes only; never a hash, password or token.
 */
class LegacyImportUsersCommand extends LegacyCommand
{
    protected $signature = 'legacy:import-users
        {--passwords=activation : activation (default: unusable password, activate with users:set-password or the reset e-mail) | preserve-hash (copy the legacy bcrypt hash; requires the owner decision in ADR-016)}
        {--dry-run : Report what would happen, then roll back}';

    protected $description = 'Import legacy staff accounts (identity + role; password policy per ADR-016). Never overwrites an existing V2 account.';

    public function handle(LegacyUserImporter $importer): int
    {
        return $this->guarded(function () use ($importer) {
            $result = $importer->import((string) $this->option('passwords'), (bool) $this->option('dry-run'));

            $this->table(['Legacy id', 'E-mail (masked)', 'Legacy role', 'V2 role', 'Outcome'],
                array_map(fn ($r) => [$r['legacy_id'], $r['email'], $r['legacy_role'], $r['v2_role'] ?? '—', $r['outcome']], $result['rows']));
            $this->printCounts('Accounts', [
                'legacy accounts' => $result['accounts'],
                'created' => $result['created'],
                'skipped (already in V2)' => $result['skipped_existing'],
                'held for a decision' => $result['held'],
                'password policy' => $result['mode'],
            ]);
            $this->info($result['dry_run'] ? 'Dry run: nothing was written.' : 'Done. Accounts created with the activation policy cannot sign in until activated.');

            return self::SUCCESS;
        });
    }
}
