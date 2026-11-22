<?php

namespace App\Console\Commands;

use App\Enums\Permission;
use App\Http\Controllers\Api\Support\ApiPrincipal;
use App\Http\Middleware\ApiAuthorize;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * Issues a read-only API token. Tokens are the only API credential: they are created here, by
 * an operator acting for an ACTIVE ADMIN (--issued-by), never through the web UI or the API.
 * The plain-text token is printed exactly once; only its SHA-256 hash is stored (Sanctum) and
 * it is never logged or written to the audit trail.
 */
class ApiTokenCommand extends Command
{
    protected $signature = 'api:token
        {email : E-mail of the active account the token acts as (needs providers.view)}
        {--issued-by= : E-mail of the active Admin account authorizing this issuance (required)}
        {--name= : Label for the token (default "api")}
        {--expires-days= : Lifetime in days (default providers.api.token_ttl_days)}';

    protected $description = 'Issue a read-only /api/v2 bearer token (Admin-authorized; shown once).';

    public function handle(AuditLogger $audit): int
    {
        $issuer = User::query()->where('email', mb_strtolower((string) $this->option('issued-by')))->first();
        if (! $issuer instanceof User || ! $issuer->isAdmin()) {
            $this->error('Token issuance requires --issued-by=<e-mail of an active Admin account>.');

            return self::FAILURE;
        }

        $principal = ApiPrincipal::query()->where('email', mb_strtolower((string) $this->argument('email')))->first();
        if (! $principal instanceof ApiPrincipal || ! $principal->hasPermission(Permission::ProvidersView)) {
            $this->error('The account does not exist, is inactive, or lacks the providers.view permission.');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: 'api'));
        if (mb_strlen($name) > 100) {
            $this->error('The token name may not exceed 100 characters.');

            return self::FAILURE;
        }

        $days = $this->option('expires-days') !== null
            ? (int) $this->option('expires-days')
            : (int) config('providers.api.token_ttl_days', 90);
        if ($days < 1 || $days > 730) {
            $this->error('The lifetime must be between 1 and 730 days.');

            return self::FAILURE;
        }

        $expiresAt = now()->addDays($days);
        $token = $principal->createToken($name, [ApiAuthorize::ABILITY], $expiresAt);

        $audit->record(
            'api.token.issued',
            [User::class, $principal->getKey()],
            'success',
            [
                'grant_id' => $token->accessToken->getKey(),
                'abilities' => [ApiAuthorize::ABILITY],
                'expires_at' => $expiresAt->toIso8601String(),
            ],
            $issuer,
        );

        $this->warn('Copy this token now. It is stored only as a hash and cannot be shown again.');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
