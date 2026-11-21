# Native local development setup

**Verified 2026-11-21, LOCAL only.** This repository now contains a clean Laravel foundation. Production and Plesk have not been connected or verified. No legacy source, dump, credentials or media were copied into the application.

## Runtime and dependencies

| Item | Verified local version |
|---|---|
| PHP CLI | 8.4.28 NTS x64 |
| Composer | 2.11.2 |
| Node.js / npm | 24.14.1 / 11.11.0 |
| Laravel framework | 13.43.0 |
| Livewire | 4.4.9, includes Alpine; do not install a second Alpine instance |
| Tailwind / Vite | 4.3.5 / 8.3.8 |
| MariaDB | 11.4.14, isolated native process on loopback port 33316 |
| PHPUnit / Pint / Larastan / PHPStan | 12.5.45 / 1.32.3 / 3.15.0 / 2.3.7 |

PHP >=8.3 is required. Composer resolves against `config.platform.php=8.3.0` so dependency updates on PHP 8.4 do not silently introduce PHP 8.4-only dependencies. PHP 8.3.37 NTS x64 is also installed in the outer `.tools/php-8.3.37/` and the full local suite was run on it on 2026-11-21 (see "PHP 8.3 evidence" below). Hosted CI execution is separately blocked (see `docs/14_GITHUB_BOOTSTRAP.md`). Install PHP with the required Laravel extensions plus `pdo_mysql`, `pdo_sqlite`, `mbstring`, `curl`, `intl`, `zip`, `fileinfo` and XML support. Verify the actual runtime with `composer check-platform-reqs`.

No Docker, Redis, globally installed PHP tools or production connection is required. Sessions, cache and queues default to database-backed services. The three upstream framework migrations provide users/password-reset/session, cache/lock and queue/batch/failure tables only. There are no authentication routes, business tables, default accounts or business seed data.

Upstream references: [Laravel installation](https://laravel.com/docs/13.x/installation), [Livewire installation and bundled Alpine](https://livewire.laravel.com/docs/4.x/installation), [Larastan](https://github.com/larastan/larastan).

## General first setup

From the repository root, using your native PHP, Composer and Node installations:

```powershell
composer install --no-interaction --prefer-dist
npm ci --ignore-scripts
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
```

Create a dedicated empty MySQL/MariaDB database named `euro_assist_v2_local` and a user restricted to it. Edit the ignored `.env`: retain `APP_ENV=local`, `DB_HOST=127.0.0.1`, the dedicated database name and blank `DB_URL`; supply only your local port/user/password. The example intentionally has no database credentials. Mail uses a local log driver; no external mail service is configured.

For a new `.env` whose `APP_KEY` is blank, generate a fresh local key once. Do not rotate an existing development key on every install. Confirm the database target before explicitly migrating:

```powershell
php artisan key:generate
php artisan migrate
npm run build
php artisan serve --host=127.0.0.1 --port=8000 --no-reload
```

Open `http://127.0.0.1:8000/`; `/up` is the Laravel health endpoint. The home remains the upstream framework welcome page, with Livewire's bundled Alpine runtime loaded. Product UI, authentication and provider features await approved requirements. Assets build locally with system font fallbacks and no remote font download.

To work on assets interactively, run `npm run dev` in a second terminal. For database queue jobs, run `php artisan queue:work database --stop-when-empty --tries=1` explicitly in a separate terminal. No Composer lifecycle script starts workers, runs migrations, regenerates keys or copies an existing environment file. Scheduled or durable worker deployment remains a separate Plesk smoke-test requirement.

## This Windows workspace: portable tools

Portable runtime and caches live in the **outer workspace**, outside the Git repository. They are local environment details, not repository dependencies. Per-terminal variables only:

```powershell
$workspaceRoot = Split-Path (Get-Location) -Parent
$env:PATH = (Join-Path $workspaceRoot '.tools\php-8.4.28') + ';' + $env:PATH
$env:COMPOSER_HOME = Join-Path $workspaceRoot '.tools\composer-home'
$env:COMPOSER_CACHE_DIR = Join-Path $workspaceRoot '.tools\composer-cache'
$env:npm_config_cache = Join-Path $workspaceRoot '.tools\npm-cache'
php (Join-Path $workspaceRoot '.tools\composer.phar') install --no-interaction --prefer-dist
php artisan serve --host=127.0.0.1 --port=8000 --no-reload
```

The isolated MariaDB process uses `.tools/mariadb-11.4.14-winx64/bin/mariadbd.exe` with `--defaults-file=<outer workspace>/.work/euro-assist-20261121/database/server.ini`. That private configuration fixes loopback port 33316 and disables LOCAL INFILE. It is not a Windows service. If stopped, start it in a hidden window using the existing private configuration:

```powershell
$workspaceRoot = Split-Path (Get-Location) -Parent
$dbBinary = Join-Path $workspaceRoot '.tools\mariadb-11.4.14-winx64\bin\mariadbd.exe'
$dbConfig = Join-Path $workspaceRoot '.work\euro-assist-20261121\database\server.ini'
Start-Process -FilePath $dbBinary -ArgumentList ('--defaults-file="' + $dbConfig + '"') -WindowStyle Hidden
```

Database-scoped local and test credentials are held outside Git in `.work/euro-assist-20261121/database/connections.json`; root client options are in that directory's private `root-client.ini`. Use only the `v2_local` and `v2_test` entries for this application, never the legacy-import entry. Never copy those private files into the repository. Do not reinitialize the existing data directory. Sandbox loopback sockets and Windows PHP readability checks required elevated local execution in this Codex session; ordinary native terminals do not use that sandbox.

## Automated checks

```powershell
composer check
composer check-platform-reqs
composer audit --locked
npm audit --audit-level=low
npm run build
python -m unittest discover -s tests/repository -v
python scripts/check_repo_hygiene.py
```

`composer check` validates Composer strictly, checks Pint formatting, runs Larastan level 6, and runs the entire default PHPUnit suite. `composer format` applies formatting. The six default tests cover home/runtime inclusion, health, isolation, Livewire mount/action, framework migration rollback/reapply and a synthetic job persisted and processed through database queue/cache. No account is seeded.

`phpunit.xml` forces SQLite `:memory:` and clears `DB_URL` in both environment and server variables; the latter matters because Laravel reads inherited `$_SERVER` values first. It overrides an existing `.env` or inherited database settings. Tests generate ephemeral encryption keys instead of requiring a committed key. The test harness refuses cached configuration, a non-testing environment, database URLs, non-memory SQLite, and any MySQL target other than loopback `euro_assist_v2_test` before test migrations execute. Remove local config cache with `php artisan config:clear` before testing if necessary.

SQLite checks do **not** establish MySQL/MariaDB compatibility. The optional MySQL suite uses `phpunit.mysql.xml` and runs only the two infrastructure integration tests. It explicitly rolls back/reapplies framework migrations on the disposable `euro_assist_v2_test` database, not the development database. Create that database with a separately scoped test user first, then supply `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` from your private local configuration and run:

```powershell
php vendor/phpunit/phpunit/phpunit --configuration phpunit.mysql.xml
```

On this workspace, load only the private test entry without printing credentials:

```powershell
$workspaceRoot = Split-Path (Get-Location) -Parent
$testConnection = (Get-Content -Raw (Join-Path $workspaceRoot '.work\euro-assist-20261121\database\connections.json') | ConvertFrom-Json).v2_test
$env:DB_PORT = [string]$testConnection.port
$env:DB_USERNAME = $testConnection.username
$env:DB_PASSWORD = $testConnection.password
php vendor/phpunit/phpunit/phpunit --configuration phpunit.mysql.xml
Remove-Item Env:DB_PORT,Env:DB_USERNAME,Env:DB_PASSWORD
```

The optional config forces `DB_HOST=127.0.0.1`, `DB_DATABASE=euro_assist_v2_test` and empty `DB_URL` in both environment sources. Verified locally: SQLite 6 tests/26 assertions; MariaDB 2 tests/17 assertions; inherited synthetic DB environment also passed the SQLite suite after the dual-source isolation fix. Composer audit and npm audit reported zero known vulnerabilities; build, formatting and static analysis passed. CI definitions were added for PHP 8.3/8.4 and Node 24; hosted runs are not yet verified. Plesk, production, external integrations and business behavior remain unverified.


## PHP 8.3 evidence (local, 2026-11-21, recovery session)

Run with `.tools/php-8.3.37` first on PATH (per-terminal only), Composer 2.11.2, existing `vendor/`:

| Command | Result |
|---|---|
| `composer check-platform-reqs` | exit 0, all requirements `success` on PHP 8.3.37 |
| `composer check` (validate, Pint, Larastan level 6, PHPUnit) | exit 0; Pint passed, PHPStan no errors, PHPUnit 6 tests / 26 assertions OK |
| `phpunit --configuration phpunit.mysql.xml` against local MariaDB 11.4.14 (`euro_assist_v2_test`) | exit 0; 2 tests / 17 assertions OK |
| Same default suite on PHP 8.4.28 | exit 0; 6 tests / 26 assertions OK |
| `composer audit --locked`, `npm audit --audit-level=low` | exit 0; no advisories / 0 vulnerabilities |
| `npm run build` | exit 0 (Vite 8.3.8) |
| `python -m unittest discover -s tests/repository -v`, `python scripts/check_repo_hygiene.py` | exit 0; 18 tests OK; hygiene PASS |
| Gitleaks 8.30.1: staged changes and full history | exit 0; no leaks found |

This is local evidence only: Windows, portable PHP, loopback MariaDB. It does not prove Linux CI, Plesk, or production behavior. The database queue is covered by the synthetic job test inside the suite (SQLite and MariaDB); a long-running worker under Plesk remains unverified (P6.1, #34).
