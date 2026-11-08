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

Official reference: https://docs.plesk.com/ and https://laravel.com/docs/deployment
