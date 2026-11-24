# Handoff: open this document in every new chat

**Date:** 2026-11-23. Read AGENTS, README, `docs/01_CURRENT_STATUS.md`, ADRs (`docs/10_DECISIONS.md`, esp. 011–014) and the work log first; verify Git/PRs yourself.

## Current true state

- One checkout: `N:\Coding\programming\VS code\Providers\euro-assist-providers-v2`. No worktrees, no re-clone. Outer `.tools/` (PHP 8.3.37/8.4.28, Composer, MariaDB 11.4.14, Gitleaks) and `.work/` (private DB config/credentials, backups, evidence) stay outside Git.
- Branch `feature/p1-foundation-hardening` = PR #40 (Draft, stacked on PR #38). Nothing merged, nothing deployed, hosted CI never ran (billing).
- The frozen legacy snapshot has been migrated LOCALLY into `euro_assist_v2_local` (2,185 providers + 3 featured, all unpaired; 9 rows held for review; 334 pair suggestions pending). See `docs/05_DATA_MIGRATION.md` (2026-11-22 section).
- Private evidence: `.work/migration-20261122/` (pre-migration dev DB dump, source fingerprints before/after, integrity/fidelity SQL, benchmarks, final checks, `run-legacy.ps1`).

## Owner UAT (verified 2026-11-22)

`start-uat.ps1` (foreground; Enter stops) / `start-uat.ps1 -NoWait` + `stop-uat.ps1`; `reset-uat.ps1` restores `euro_assist_v2_uat` from the SHA-256-verified baseline in `.work/uat/baseline` (refuses other targets, a running app, or a wrong confirmation phrase; verifies `euro_assist_v2_local` and the legacy DB unchanged). `php artisan uat:check` is the fail-closed target check the scripts use. The UAT DB account `v2_uat` (ALL on that DB only) lives in the private `connections.json`. UAT password files: `storage/app/private/provisioned/uat/` (never print or commit). Port 8000 on this machine is used by an unrelated program, so the launcher reports and uses 8001.

## Local startup for development (verified 2026-11-22)

```powershell
$ws = Split-Path (Get-Location) -Parent
$env:PATH = (Join-Path $ws '.tools\php-8.3.37') + ';' + $env:PATH
# MariaDB: if 127.0.0.1:33316 is closed, start it as in docs/07_LOCAL_SETUP.md (hidden process, private server.ini)
npm run build
php artisan serve --host=127.0.0.1 --port=8000 --no-reload
```

In a second terminal (needed for imports/exports):

```powershell
php artisan queue:work database --tries=1
```

`.env` already points to `euro_assist_v2_local`. Accounts `admin@`/`editor@`/`viewer@local.test`; passwords in `storage/app/private/provisioned/*.txt` (gitignored, never print or commit; delete after UAT). UAT script: `docs/16_UAT_CHECKLIST.md`.

## Running the legacy pipeline (local only)

Use the private helper, which injects the SELECT-only `legacy_reader` credentials and the destination from `.work/euro-assist-20261121/database/connections.json` without printing them:

```powershell
& '..\.work\migration-20261122\run-legacy.ps1' legacy_reader v2_local legacy:preflight --snapshot-sha256=19a71fe301c1038b52473b76bec64d2a95ca606dd701c4a2054c72f5bb791814
```

Order: preflight → stage → map-types --from-legacy → apply --actor=admin@local.test --accept-provisional-types → suggest-pairs → reconcile --expect-baseline --report. Rollback: `legacy:rollback <batch-uuid>` (rehearse on `v2_rehearsal`). Never use the `legacy_import` account (write-capable; the guard refuses it).

## Checks before every commit

Pint, PHPStan, PHPUnit on PHP 8.3 and 8.4, `phpunit.mysql.xml` on MariaDB (docs07), composer/npm audit, `npm run build`, `python scripts/check_repo_hygiene.py`, Python repo tests, Gitleaks `git --staged`, `git diff --cached --check`.

## Release candidate 2.0.0-rc.1 (2026-11-22)

* Branch `feature/release-candidate` (PR stacked on #41). Build from the repository root: `python scripts/release/build_release.py --version 2.0.0-rc.1 --out ..\.work\release-20261122\out --data-sql ..\.work\release-20261122\build\EuroAssist_Providers_V2_DATA_2.0.0-rc.1.sql --php ..\.tools\php-8.3.37\php.exe --composer ..\.tools\composer.phar` (set `COMPOSER_HOME`, `COMPOSER_CACHE_DIR`, `npm_config_cache` to the `.tools` folders; first build ~10 min on N:).
* Verify a built ZIP exactly as `docs/18_RELEASE_PACKAGE.md` §4 (scripts used: ZIP audit, SQL restore into `euro_assist_v2_install_rehearsal`, HTTP role matrix — recreate them from that section; they are not in the repository).
* Release DB `euro_assist_v2_release` (account `v2_release` in the private `connections.json`) is the source of the SQL. Rebuild it only from a snapshot through the pipeline, never by copying `euro_assist_v2_local`.
* IT guides: `deploy/it-package/`. Gates: `docs/01_CURRENT_STATUS.md`.

## Release candidate 2.0.0-rc.3 (2026-11-23)

* Dataset: `euro_assist_v2_release_rc3`, rebuilt with `..\.work\migration-review-20261123\build-rc3.ps1` (pipeline → import-users → `legacy:apply-review-manifest` → `legacy:import-media` → reconcile), then `cleanup-rc3.sql`. The private runner `run.ps1` allows only the rc3 databases.
* Build: `python scripts/release/build_release.py --version 2.0.0-rc.3 --out ..\.work
elease-20261123-rc3\out --data-sql <rc3 SQL> --media-dir <rc3 media folder> --php ..\.tools\php-8.3.37\php.exe --composer ..\.tools\composer.phar`.
* **Newest candidate: 2.0.0-rc.5**, SHA-256 `87ed23070d738f9d7837824c300651d7a6f7fd4fbdd25bfff149c2a866dd2c19` (pre-activated Admin with a temporary password, ADR-019; package details in the work log 2026-11-24). Superseded: rc.4. The credentials are kept outside the repository and the package and are sent separately.
* Previous delivery package: 2.0.0-rc.4 (`../.work/release-20261124-rc4/out/`, handoff folder `../.work/IT-handoff-rc4/`, SHA-256 `05650fa73591eb69895ad68f2f5196ede46f91ff322935c8d677128e3a61eee1`; documentation-only change over rc.3). The text below about rc.3 describes the same application and data.
* Built package (rc.3, superseded): `..\.work
elease-20261123-rc3\out\EuroAssist_Providers_V2_2.0.0-rc.3.zip`, built from `ff9f76f`, 6,763 files, SHA-256 `c3eaf2995a434b99fa3d4f7aa025c8d6b93e58a15a9b4daa7395f67cafc7efeb`; verified and install-rehearsed locally (work log 2026-11-24). Send IT the ZIP and, separately, its SHA-256.
* Open human-review items (non-blocking, visible on the Admin migration strip): 2 Eye Center rows, 104 held pairs, 195 open suggestions (incl. the manifest's 110 holds and rows outside unique links).
* A batch staged BEFORE the time-zone fix fails every manifest fingerprint (`source_row_changed`): re-stage instead of reusing it.
* Follow-ups accepted in review R6: rollback leaves media files; stale picker file name; `gd` with WebP required in production.

## Exactly what to do next

1. King: review the release-candidate PR; authorize an isolated Plesk staging install; send IT the ZIP and (separately) its SHA-256; answer D3 (4 labels, "Eye Center", 2 map links) and D7 (who calls the old `/api/...`); fix GitHub billing.
1b. IT: staging per `deploy/it-package/INSTALL_GUIDE.md`, results per `VERIFY_AND_SMOKE_TEST.md`.
2. With the owner: work through the 334 pair suggestions on the review screen (reason required for every decision).
3. Independent final review of the merged-candidate diff; then isolated Plesk staging only after written approval (`docs/09_PLESK_DEPLOYMENT.md` checklist).

## Known risks

- Provisional type mappings (972 rows) depend on owner approval of D3; reversible by rollback + re-apply.
- Any new pre-authentication middleware must be put in the middleware priority list (ADR-013).
- API consumers are unknown; `website`/`map_url` are untrusted text for them (OpenAPI).
- Local benchmarks are not staging evidence.

## Guardrails

No production DB/credentials/deploy/DNS/Plesk changes/key rotation/cutover without separate express authorization. Never commit `.env`, dumps, real records, provisioned password files, exports or uploads. No force-push; no merge without the owner.
