<?php
/**
 * Euro Assist Providers V2 - READ-ONLY production preflight (works on ANY installed version, no Laravel boot,
 * no `ops:check` needed). It never writes, never runs migrations, never prints secrets (no keys, passwords,
 * tokens, hosts or user names). Output is meant to be sent to King as text.
 *
 * Usage (same PHP 8.3/8.4 CLI that Plesk uses for the site):
 *     php preflight.php "D:\path\to\application-folder-with-artisan"
 *
 * It answers: which PHP, which environment/debug settings, which database (name only) and is it the V2 one,
 * which role values exist, how many providers/jobs, is the queue draining, and WHICH CODE FILES DIFFER from the
 * delivered rc.5 (rc5-baseline-fingerprint.json lists the sha256 of every rc.5 code file).
 */
declare(strict_types=1);

$app = rtrim((string) ($argv[1] ?? ''), "\\/");
if ($app === '' || ! is_file($app.'/artisan')) {
    fwrite(STDERR, "Usage: php preflight.php <application folder that contains 'artisan'>\n");
    exit(2);
}
$fail = 0;
$say = function (string $status, string $name, string $detail) use (&$fail): void {
    if ($status === 'FAIL') {
        $fail++;
    }
    printf("  [%-4s] %-34s %s\n", $status, $name, $detail);
};

echo "EURO ASSIST PREFLIGHT (read-only)\n";
echo 'Application folder: '.$app."\n\nPHP\n";
$say(version_compare(PHP_VERSION, '8.3.0', '>=') ? 'OK' : 'FAIL', 'PHP '.PHP_VERSION, PHP_SAPI.' (must be CLI 8.3 or 8.4)');
foreach (['pdo_mysql', 'mbstring', 'openssl', 'gd', 'zip', 'xml', 'curl', 'fileinfo'] as $ext) {
    $say(extension_loaded($ext) ? 'OK' : 'FAIL', 'extension '.$ext, extension_loaded($ext) ? 'loaded' : 'MISSING in this PHP');
}

// .env: read ONLY an allow-list of non-secret keys.
$env = [];
if (is_file($app.'/.env')) {
    foreach (file($app.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] !== '#' && str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $env[trim($k)] = trim($v, " \t\"'");
        }
    }
}
echo "\nCONFIGURATION (.env, non-secret keys only)\n";
$say(($env['APP_ENV'] ?? '') === 'production' ? 'OK' : 'FAIL', 'APP_ENV', $env['APP_ENV'] ?? '(not set)');
$say(strtolower($env['APP_DEBUG'] ?? '') === 'false' ? 'OK' : 'FAIL', 'APP_DEBUG', $env['APP_DEBUG'] ?? '(not set: defaults to debug OFF only if set to false)');
$say(str_starts_with($env['APP_URL'] ?? '', 'https://') ? 'OK' : 'WARN', 'APP_URL', $env['APP_URL'] ?? '(not set)');
foreach (['DB_CONNECTION', 'CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION', 'SESSION_SECURE_COOKIE'] as $k) {
    $say('INFO', $k, $env[$k] ?? '(not set)');
}
$say(isset($env['DB_URL']) && $env['DB_URL'] !== '' ? 'WARN' : 'OK', 'DB_URL override', isset($env['DB_URL']) && $env['DB_URL'] !== '' ? 'SET: it silently overrides DB_HOST/DB_DATABASE' : 'not set');
$say(is_file($app.'/bootstrap/cache/config.php') ? 'INFO' : 'INFO', 'config cache', is_file($app.'/bootstrap/cache/config.php') ? 'present (stale cache can hide .env changes: run config:clear)' : 'absent');
$say(is_file($app.'/bootstrap/cache/routes-v7.php') ? 'INFO' : 'INFO', 'route cache', is_file($app.'/bootstrap/cache/routes-v7.php') ? 'present' : 'absent');
foreach (['storage/logs', 'storage/framework', 'storage/app/private', 'bootstrap/cache'] as $d) {
    $say(is_writable($app.'/'.$d) ? 'OK' : 'FAIL', 'writable '.$d, is_writable($app.'/'.$d) ? 'yes (for the user running this)' : 'NO');
}

echo "\nDATABASE (SELECT only)\n";
try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $env['DB_DATABASE'] ?? '');
    $pdo = new PDO($dsn, $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $one = fn (string $sql) => $pdo->query($sql)->fetchColumn();
    $say('OK', 'connected to database', (string) $one('SELECT DATABASE()'));
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $has = fn (string $t) => in_array($t, $tables, true);
    $legacyV1 = $has('arabic_providers') && ! $has('provider_translations');
    $say($legacyV1 ? 'FAIL' : 'OK', 'database generation', $legacyV1 ? 'This is the OLD V1 database (arabic_providers, no provider_translations). STOP: do not migrate or import here.' : 'V2 schema');
    $missing = array_values(array_filter(['users', 'providers', 'provider_translations', 'provider_types', 'cache', 'cache_locks', 'sessions', 'jobs', 'failed_jobs', 'migrations', 'audit_events', 'export_jobs'], fn ($t) => ! $has($t)));
    $say($missing === [] ? 'OK' : 'FAIL', 'required tables', $missing === [] ? 'all present' : 'MISSING: '.implode(', ', $missing));
    if ($has('providers')) {
        $say('INFO', 'providers (live/total)', $one('SELECT COUNT(*) FROM providers WHERE deleted_at IS NULL').' / '.$one('SELECT COUNT(*) FROM providers'));
    }
    if ($has('users')) {
        $roles = $pdo->query('SELECT role, COUNT(*) n FROM users GROUP BY role')->fetchAll(PDO::FETCH_KEY_PAIR);
        $say('INFO', 'users by role', json_encode($roles));
        $odd = array_diff(array_keys($roles), ['admin', 'editor', 'viewer']);
        $say($odd === [] ? 'OK' : 'WARN', 'non-standard roles', $odd === [] ? 'none' : implode(', ', $odd).' (the update converts super_admin to admin; others stay and hold no permissions)');
        $say('INFO', 'active administrators', (string) $one("SELECT COUNT(*) FROM users WHERE role IN ('admin','super_admin') AND is_active = 1"));
    }
    if ($has('migrations')) {
        $say('INFO', 'migrations recorded', $one('SELECT COUNT(*) FROM migrations').' (last: '.$one('SELECT migration FROM migrations ORDER BY id DESC LIMIT 1').')');
    }
    if ($has('jobs')) {
        $oldest = $one('SELECT MIN(created_at) FROM jobs');
        $age = $oldest ? time() - (int) $oldest : 0;
        $say($age > 300 ? 'FAIL' : 'OK', 'pending jobs', $one('SELECT COUNT(*) FROM jobs').' pending'.($age ? ", oldest {$age}s" : '').($age > 300 ? ' (nothing is processing the queue)' : ''));
        $say('INFO', 'failed jobs', (string) $one('SELECT COUNT(*) FROM failed_jobs'));
    }
    if ($has('export_jobs')) {
        $say('INFO', 'exports by status', json_encode($pdo->query('SELECT status, COUNT(*) n FROM export_jobs GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR)));
    }
} catch (Throwable $e) {
    $say('FAIL', 'database', 'cannot connect/read: '.get_class($e).' (check .env DB_* and a stale config cache)');
}

echo "\nCODE vs the delivered rc.5 (sha256, line endings normalised)\n";
$baseFile = __DIR__.'/rc5-baseline-fingerprint.json';
if (! is_file($baseFile)) {
    $say('WARN', 'baseline', 'rc5-baseline-fingerprint.json missing next to this script');
} else {
    $base = json_decode((string) file_get_contents($baseFile), true)['files'];
    $actual = [];
    foreach (['app', 'bootstrap/app.php', 'config', 'database/migrations', 'database/seeders/DatabaseSeeder.php', 'lang', 'resources', 'routes', 'public/index.php'] as $entry) {
        $path = $app.'/'.$entry;
        $files = is_file($path) ? [new SplFileInfo($path)] : (is_dir($path) ? iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)), false) : []);
        foreach ($files as $f) {
            if ($f->isFile()) {
                $actual[str_replace('\\', '/', substr($f->getPathname(), strlen($app) + 1))] = hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($f->getPathname())));
            }
        }
    }
    $changed = array_keys(array_diff_assoc(array_intersect_key($actual, $base), $base));
    $missing = array_keys(array_diff_key($base, $actual));
    $extra = array_keys(array_diff_key($actual, $base));
    $same = count($base) - count($changed) - count($missing);
    $say($changed === [] && $missing === [] && $extra === [] ? 'OK' : 'WARN', 'rc.5 code files', sprintf('%d identical, %d modified, %d missing, %d extra (not in rc.5)', $same, count($changed), count($missing), count($extra)));
    foreach (['modified' => $changed, 'missing' => $missing, 'extra' => $extra] as $label => $list) {
        foreach (array_slice($list, 0, 40) as $f) {
            echo "           $label: $f\n";
        }
        if (count($list) > 40) {
            echo '           ... and '.(count($list) - 40)." more $label\n";
        }
    }
}

echo "\nRESULT: ".($fail === 0 ? 'no blocking item' : "$fail blocking item(s): fix or report them to King BEFORE applying the update")."\n";
echo "Send this whole output to King. It contains no secrets.\n";
exit($fail === 0 ? 0 : 1);
