<?php

namespace App\Console\Commands;

use App\Models\LegacyMigrationBatch;
use App\Models\User;
use App\Services\Legacy\BatchLocator;
use App\Services\Legacy\LegacyException;
use App\Services\Legacy\LegacyGuard;
use Illuminate\Console\Command;

/**
 * Base class of the legacy:* commands. Every command refuses to run in production, prints only
 * counts (never source values) and turns a deliberate LegacyException into a failure exit code.
 */
abstract class LegacyCommand extends Command
{
    /** Runs $callback behind the production guard; refusals become a non-zero exit. */
    protected function guarded(callable $callback): int
    {
        try {
            LegacyGuard::assertNotProduction();

            return (int) $callback();
        } catch (LegacyException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    protected function resolveBatch(): LegacyMigrationBatch
    {
        $ref = $this->input->hasOption('batch') ? $this->input->getOption('batch') : null;
        if (is_string($ref) && $ref !== '') {
            return BatchLocator::find($ref);
        }

        return BatchLocator::latestActive() ?? throw new LegacyException('No active migration batch; stage one first (legacy:stage).');
    }

    protected function resolveAdmin(?string $email): ?User
    {
        if ($email === null || $email === '') {
            return null;
        }

        return User::query()->where('email', mb_strtolower($email))->first();
    }

    /** @param array<string, int|string> $counts */
    protected function printCounts(string $title, array $counts): void
    {
        $this->line($title);
        $this->table(['Measure', 'Value'], collect($counts)->map(fn ($v, $k) => [$k, $v])->values()->all());
    }
}
