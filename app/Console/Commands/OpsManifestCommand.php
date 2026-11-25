<?php

namespace App\Console\Commands;

use App\Services\OpsHealth;
use Illuminate\Console\Command;

/** Build-time: writes release-manifest.json (sha256 of every code file) so `ops:check` can prove what is deployed. */
class OpsManifestCommand extends Command
{
    protected $signature = 'ops:manifest {version : release label, e.g. 2.0.0-rc.6} {--path= : output file (default base_path/release-manifest.json)}';

    protected $description = 'Write the release manifest used by ops:check (build step; not run on the server).';

    public function handle(): int
    {
        $files = OpsHealth::fingerprint();
        unset($files['release-manifest.json']);
        $path = (string) ($this->option('path') ?: base_path('release-manifest.json'));
        file_put_contents($path, json_encode(['version' => $this->argument('version'), 'files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->info(count($files).' files fingerprinted -> '.$path);

        return self::SUCCESS;
    }
}
