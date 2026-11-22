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
