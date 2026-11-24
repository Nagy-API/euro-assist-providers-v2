<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The application has exactly three roles: admin, editor, viewer. The legacy system stored
 * `super_admin` for its administrator; a database that still holds that value is normalized to
 * `admin` (the same privileges: the legacy account was always a full administrator), so nothing is
 * lost and nobody is locked out.
 *
 * Safe and non-destructive:
 *  - only rows whose role is exactly `super_admin` are touched; no other column, no password hash,
 *    session or token is modified;
 *  - one audit event per account records the previous value, so the change is traceable;
 *  - unknown values other than `super_admin` are NOT guessed or promoted (they stay as stored and
 *    the application treats them as "no permissions"; `ops:check` reports them);
 *  - idempotent: a second run finds nothing to change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'role')) {
            return;
        }

        $ids = DB::table('users')->where('role', 'super_admin')->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id) {
                $changed = DB::table('users')->where('id', $id)->where('role', 'super_admin')->update(['role' => 'admin']);

                if ($changed === 1 && Schema::hasTable('audit_events')) {
                    DB::table('audit_events')->insert([
                        'actor_id' => null,
                        'action' => 'user.role_normalized',
                        'target_type' => 'user',
                        'target_id' => (string) $id,
                        'result' => 'success',
                        'metadata' => json_encode(['from' => 'super_admin', 'to' => 'admin', 'reason' => 'ADR-020 three roles only']),
                        'ip_address' => null,
                        'created_at' => now(),
                    ]);
                }
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: `super_admin` and `admin` carry identical privileges, and the previous
        // value of every changed account is recorded in the audit log (action user.role_normalized).
    }
};
