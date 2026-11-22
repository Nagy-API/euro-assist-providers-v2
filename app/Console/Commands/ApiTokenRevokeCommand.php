<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\Support\ApiPrincipal;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/** Revokes one (--id) or all (--all) API tokens of an account. Admin-authorized like issuance. */
class ApiTokenRevokeCommand extends Command
{
    protected $signature = 'api:token-revoke
        {email : E-mail of the token owner}
        {--issued-by= : E-mail of the active Admin account authorizing the revocation (required)}
        {--id= : Id of one token to revoke}
        {--all : Revoke every token of the account}';

    protected $description = 'Revoke /api/v2 bearer tokens of an account.';

    public function handle(AuditLogger $audit): int
    {
        $issuer = User::query()->where('email', mb_strtolower((string) $this->option('issued-by')))->first();
        if (! $issuer instanceof User || ! $issuer->isAdmin()) {
            $this->error('Revocation requires --issued-by=<e-mail of an active Admin account>.');

            return self::FAILURE;
        }

        $principal = ApiPrincipal::query()->where('email', mb_strtolower((string) $this->argument('email')))->first();
        if (! $principal instanceof ApiPrincipal) {
            $this->error('No such account.');

            return self::FAILURE;
        }

        if (! $this->option('all') && $this->option('id') === null) {
            $this->error('Pass --id=<token id> or --all.');

            return self::FAILURE;
        }

        $query = $principal->tokens();
        if (! $this->option('all')) {
            $query->whereKey((int) $this->option('id'));
        }
        $revoked = $query->delete();

        $audit->record('api.token.revoked', [User::class, $principal->getKey()], 'success', ['count' => $revoked], $issuer);
        $this->info("Revoked {$revoked} token(s).");

        return self::SUCCESS;
    }
}
