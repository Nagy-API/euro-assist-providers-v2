<?php

namespace App\Console\Commands;

use App\Services\OpsHealth;
use Illuminate\Console\Command;

/** Read-only deployment/queue self-check for IT (docs/21_PRODUCTION_UPDATE_GUIDE.md). Exit code 1 when any check FAILS. */
class OpsCheckCommand extends Command
{
    protected $signature = 'ops:check {--json : Machine readable output}';

    protected $description = 'Read-only check of environment, database, roles, routes, scheduler/queue and deployed code. Prints no secrets.';

    public function handle(OpsHealth $health): int
    {
        $checks = $health->run();

        if ($this->option('json')) {
            $this->line((string) json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $group = '';
            foreach ($checks as $c) {
                if ($c['group'] !== $group) {
                    $group = $c['group'];
                    $this->newLine();
                    $this->line('<options=bold>'.strtoupper($group).'</>');
                }
                $tag = match ($c['status']) {
                    OpsHealth::OK => '<fg=green>[ OK ]</>',
                    OpsHealth::WARN => '<fg=yellow>[WARN]</>',
                    default => '<fg=red>[FAIL]</>',
                };
                $this->line(sprintf('  %s %-34s %s', $tag, $c['name'], $c['detail']));
            }
            $this->newLine();
        }

        $failed = OpsHealth::failed($checks);
        if (! $this->option('json')) {
            $failed === 0 ? $this->info('No failing check.') : $this->error("$failed check(s) FAILED.");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
