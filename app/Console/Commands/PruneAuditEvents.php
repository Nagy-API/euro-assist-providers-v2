<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use Illuminate\Console\Command;

/** Keeps the audit table bounded: failed-login noise is short-lived, everything else long-lived. */
class PruneAuditEvents extends Command
{
    protected $signature = 'audit:prune {--failed-days=30} {--days=730}';

    protected $description = 'Delete old audit events (failed logins sooner than the rest).';

    public function handle(): int
    {
        $failed = AuditEvent::query()->where('action', 'auth.login_failed')
            ->where('created_at', '<', now()->subDays((int) $this->option('failed-days')))->delete();
        $old = AuditEvent::query()->where('created_at', '<', now()->subDays((int) $this->option('days')))->delete();

        $this->info("Pruned {$failed} failed-login and {$old} aged audit events.");

        return self::SUCCESS;
    }
}
