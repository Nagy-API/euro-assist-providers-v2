# Plesk deployment design (assumed, NOT verified)

## Planning assumptions

- Hosting uses Plesk without Docker, but can provide a compatible PHP handler/CLI, Composer (or build artifacts), MySQL/MariaDB and restricted scheduled tasks.
- Preferred V2 runtime is PHP >= 8.3 and an appropriate Laravel version.
- Queue strategy: durable worker if supported; otherwise evaluate `queue:work --stop-when-empty` under safe scheduled invocation + overlap protection, or a supported Plesk Laravel Toolkit strategy.
- Application and assets can be deployed with document root pointed at Laravel `public/` and all secrets outside the repository.

## Required pre-deployment smoke test (separate staging only)

- OS and Plesk edition/version; PHP **web handler + CLI** versions; enabled extensions; CLI path.
- Composer commands and file access; PHP memory/time limits; storage and upload quota.
- Domain/subdomain permission; document root `public/`; rewrite/HTTPS behavior; mail/log permissions.
- Scheduled task frequency/permissions; whether queue processes survive or need bounded runs; whether jobs may overlap.
- Test a synthetic import/export on staging and inspect logs/memory/permissions/retries.

## Fallback decision tree

- PHP version incompatible: ask host to enable newer PHP or switch compatible hosting; **do not** rewrite entire app casually.
- Queue daemon unavailable: isolate job runner behind a queue interface and use scheduled bounded workers if empirical tests pass.
- Shared-host quotas insufficient: consider a VPS or application hosting that runs the same code. This is a hosting migration, not a language rewrite.
- If an entirely different runtime is mandated, create a formal ADR with a migration estimate before choosing.

## Cutover safeguards

- Production remains unchanged until an explicitly approved maintenance/deployment step.
- Verified backup + restore + rollback plan; final data checksum/reconciliation; HTTPS/domain configuration; smoke tests; error monitoring; named owner of rollback decision.
- No production dump committed to Git or publicly accessible on staging.

## Production environment variables (planning; nothing here has been deployed)

Set in the server's real environment or an untracked `.env` created on the server only. Never commit it.

| Variable | Required value | Why |
|---|---|---|
| `APP_ENV` | `production` | Enables production behavior. |
| `APP_DEBUG` | `false` | The application **refuses to boot** with `APP_ENV=production` and `APP_DEBUG=true` (`AppServiceProvider`, tested). |
| `APP_URL` | `https://...` | HTTPS-only canonical URL. |
| `APP_KEY` | generated once on the server (`php artisan key:generate --force` the first time only) | Back it up separately; rotating it invalidates sessions and encrypted data. |
| `LOG_STACK` / `LOG_CHANNEL` | `daily` | Bounded log growth; no `single` file. |
| `LOG_LEVEL` | `warning` or higher | Avoid verbose request data in logs. |
| `SESSION_SECURE_COOKIE` | `true` | Cookies only over HTTPS. |
| `SESSION_ENCRYPT` | `true` | Encrypt session payloads at rest. |
| `SESSION_SAME_SITE` | `lax` (default) | CSRF defense in depth. |
| `APP_URL` host | exactly the public host | Outside `local` (staging and production) the app **only answers to this exact host** (`trustHosts`, read from config per request, safe with `config:cache`) and pins generated links (password-reset/invitation mails) to it; boot refuses a non-https `APP_URL`. |
| `TRUSTED_PROXIES` | proxy IPs, comma separated | Unset = no proxy trusted. Behind Plesk/nginx set the proxy address so the real client IP is used for rate limiting and audit; otherwise all staff share one IP bucket. Also `PROVIDERS_API_IP_RATE_LIMIT` (default 300/min) bounds API calls per IP before token authentication. |
| `DB_*` | dedicated least-privilege account | Never the root or legacy account. |
| `QUEUE_CONNECTION` | `database` | See worker strategy below. |
| `PROVIDERS_API_PUBLIC_READ` | `false` until approved | Public read exposure is disabled by default (D7 pending). |

Also: document root = `public/` only; `storage/` and `bootstrap/cache` writable by the PHP user only; `/storage` symlink is **not** created (all private files use the `local` disk, `serve` disabled); `robots.txt` is `Disallow: /` until launch is approved; `php artisan config:cache route:cache view:cache event:cache` after each deploy; trusted proxies/hosts configured for the real reverse-proxy chain before go-live.

## Queue worker and scheduler strategy (unverified on Plesk)

1. Preferred: a durable `php artisan queue:work database --max-time=3600 --tries=3` supervised by Plesk/Laravel Toolkit, if available.
2. Fallback (not assumed reliable): a Plesk scheduled task every minute running `php artisan schedule:run`. The scheduler registers `queue:work database --stop-when-empty --max-time=55 --tries=3` with `withoutOverlapping()`, so a short-running worker drains the queue without a daemon. Import/export jobs are resumable from DB checkpoints, so an interrupted worker leaves a visible, resumable state, never a silent partial success.
3. `php artisan imports:prune` and `exports:prune` (scheduled daily) delete expired private files.

None of this has been executed on Plesk. P6.1 (#34) must verify it on an isolated staging subscription.

Official reference: https://docs.plesk.com/ and https://laravel.com/docs/deployment

## Release-readiness checklist (prepared 2026-11-22; NOTHING deployed, Plesk NOT verified)

The application now **refuses to boot in production** unless: `APP_DEBUG=false`, `APP_URL` is `https://…`, `SESSION_SECURE_COOKIE=true`, and `TRUSTED_PROXIES` is an explicit list (not `*`).

Facts King must obtain from the Plesk host before staging (unknown today):

1. OS, PHP 8.3+ CLI and FPM versions; extensions: pdo_mysql, mbstring, intl, zip, fileinfo, xml, xmlreader, openssl, ctype, tokenizer, bcmath not required.
2. MariaDB/MySQL version (tested locally: MariaDB 11.4.14) and whether a separate staging database + user can be created.
3. Composer availability (or build `vendor/` elsewhere), Node only for building assets (or ship `public/build`).
4. Document root can be set to `public/`; PHP user can write `storage/` and `bootstrap/cache` only.
5. Scheduled tasks: can `php artisan schedule:run` run every minute? Is a long-running `queue:work` allowed (Laravel Toolkit / supervisor)?
6. Reverse proxy address(es) for `TRUSTED_PROXIES`; HTTPS certificate on the staging host name.
7. PHP `memory_limit` ≥ 256M and `upload_max_filesize`/`post_max_size` ≥ 12M (import limit 10 MB).
8. Backup facility for the database and `storage/app/private` (imports/exports/logos), and its restore procedure.

Deployment order for an **isolated staging** subscription (only after written approval): upload code → `composer install --no-dev --optimize-autoloader` → set `.env` from server secrets (`php artisan key:generate` once) → `php artisan migrate --force` on the EMPTY staging DB → `db:seed --force` (types only) → `users:provision` (interactive password) → `config:cache route:cache view:cache event:cache` → scheduler task → smoke test (`/up`, login, list, create/edit, import dry-run, export download, `/api/v2` with a token) → queue drain check. Rollback: restore DB backup + previous release directory.

Operational limits from local benchmarks (Windows laptop, PHP 8.3.37, MariaDB 11.4; staging must re-measure): validation ~330–480 rows/s, apply ~280–300 rows/s, export ~3,300 rows/s, peak memory ≤ 78 MB at 25,000 rows. Keep `IMPORTS_MAX_ROWS=20000` (≈55 s validation + ≈71 s apply) and the 10 MB file limit until staging confirms worker time limits; 25,000 rows worked locally (≈68 s + ≈84 s).

Production data migration: NOT authorized. It would require an approved ADR, a fresh frozen snapshot + checksum, a SELECT-only source account, a maintenance window, the rehearsal steps of `docs/05_DATA_MIGRATION.md`, and a tested rollback.
