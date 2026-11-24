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

    /**
     * A report lists source ids: it must be a NEW plain file outside the repository. Links, existing
     * files and folders reached through an alias of the repository are refused (review R6).
     */
    protected function assertReportPath(string $report): void
    {
        if ($report === '') {
            return;
        }
        if (file_exists($report) || is_link($report)) {
            throw new LegacyException('The report file already exists or is a link; choose a new file name.');
        }
        $dir = realpath(dirname($report));
        $base = realpath(base_path());
        if ($dir === false || $base === false || is_link(dirname($report))) {
            throw new LegacyException('The report folder does not exist or is a link.');
        }
        $inside = str_starts_with(strtolower($dir.DIRECTORY_SEPARATOR), strtolower($base.DIRECTORY_SEPARATOR));
        // Same folder reached through another name (subst drive, junction, UNC): compare by inode/marker.
        $probe = $dir.DIRECTORY_SEPARATOR.'artisan';
        if ($inside || (is_file($probe) && @fileinode($probe) === @fileinode(base_path('artisan')) && fileinode($probe) !== 0)) {
            throw new LegacyException('The report must be written outside the repository (it lists source ids).');
        }
    }

    /** @param array<string, int|string> $counts */
    protected function printCounts(string $title, array $counts): void
    {
        $this->line($title);
        $this->table(['Measure', 'Value'], collect($counts)->map(fn ($v, $k) => [$k, $v])->values()->all());
    }
}
