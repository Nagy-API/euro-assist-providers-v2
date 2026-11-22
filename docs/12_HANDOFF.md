# Handoff: open this document in every new chat

**Date:** 2026-11-22. Read AGENTS, README, status, plan, ADRs and work log first; verify Git/PRs yourself.

## Current true state

- One checkout: `N:\Coding\programming\VS code\Providers\euro-assist-providers-v2`. No worktrees, no re-clone. Outer `.tools/` (PHP 8.3.37/8.4.28, Composer, MariaDB 11.4.14, Gitleaks) and `.work/` (private DB config/credentials, evidence, backups) are outside Git.
- Branch `feature/p1-foundation-hardening` = PR #40 (Draft, stacked on PR #38). It contains the canonical schema, auth/roles, admin UI, import/export, `/api/v2`, legacy tooling and security fixes. Nothing merged.
- 2026-11-22 recovery: the interrupted multi-agent session's uncommitted work was backed up to `.work/recovery-20261122/` (tracked diff + untracked tarball), integrated, fixed and committed (`f5e5a81`), then security review R2 fixes were committed on top.
- Hosted CI has never run (billing). Production untouched.

## Local startup (verified 2026-11-22)

```powershell
$ws = Split-Path (Get-Location) -Parent
$env:PATH = (Join-Path $ws '.tools\php-8.3.37') + ';' + $env:PATH
# MariaDB: if 127.0.0.1:33316 is closed, start it as in docs/07_LOCAL_SETUP.md
php artisan migrate            # dev DB euro_assist_v2_local only (already migrated)
php artisan db:seed --class=SyntheticProvidersSeeder   # synthetic data (already seeded)
php artisan users:provision admin@local.test --role=admin --generate   # password file in storage/app/private/provisioned/ (gitignored)
npm run build
php artisan serve --host=127.0.0.1 --port=8000 --no-reload
php artisan queue:work database --stop-when-empty --tries=1   # processes import/export jobs
```

Local test accounts `admin@`, `editor@`, `viewer@local.test` exist in the dev DB; their generated passwords are in `storage/app/private/provisioned/` (never commit; delete when done).

## Checks before every commit

`vendor/bin/pint --test`, `phpstan analyse`, PHPUnit on PHP 8.3 and 8.4, `phpunit.mysql.xml` on MariaDB (credentials from `.work/.../connections.json`, see docs07), `composer audit --locked`, `npm audit`, `npm run build`, `python scripts/check_repo_hygiene.py`, `python -m unittest discover -s tests/repository`, Gitleaks `git --staged`, `git diff --cached --check`.

## Exactly what to do next

1. Owner: billing fix -> rerun Actions; review PR #38 then #40; answer D1–D8 and the clear-value permission.
2. Security: S1 (cap bytes actually read while parsing xlsx), S2 (document SELECT-only grant of the legacy account; compare resolved hosts), S3 (decide whether migration review may run in production). Then an independent re-review.
3. P5.2: run `legacy:stage` -> `map-types` -> `suggest-pairs` -> `apply --rehearsal` -> `reconcile` -> `report` against the restored frozen snapshot (read-only `legacy_audit` connection) and record aggregates only.
4. Browser-test the import flow (upload, preview, confirm, error report) with a synthetic file.

## Known risks

- Middleware priority: any new pre-auth middleware must be added to the priority list (see ADR-013), or Laravel moves it after authentication.
- Legacy rows keep their original URLs; the API nulls non-http(s) values, other consumers must use `SafeUrl`.
- D1–D8 are provisional engineering defaults, not owner sign-off.

## Guardrails

No production DB/credentials/deploy/DNS/Plesk changes/key rotation/cutover without separate express authorization. Never commit `.env`, dumps, real records, provisioned password files or uploads. No force-push; no merge without the owner.
