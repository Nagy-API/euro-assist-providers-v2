# Release package: how it is built and verified

Audience: engineers preparing an IT delivery. The IT-facing guides are in `deploy/it-package/` and ship inside the ZIP.
Decision record: ADR-015. Nothing here deploys anything.

## 1. Legacy handoff facts this layout follows (verified read-only, 2026-11-22)

* The legacy archive's top level is a byte-identical copy of the old app's `public/` folder (index.php, `.htaccess`,
  `web.config`, scripts, `uploads/`), plus `Providers.sql`, plus the full Laravel app in `providers/` (with `vendor/` and
  a real `.env`).
* `web.config`, `.user.ini` and paths under `C:\Inetpub\vhosts\…` show **Plesk on Windows/IIS** for
  `providers.euro-assist.com`. The old `public/index.php` loads `../vendor`, so the document root was the app's `public/`.
* V2 keeps the practical shape (one folder + one SQL + notes) but not the insecure parts: no `.env`, no copy of
  `public/` at the top level, no uploads, and the document root is `providers/public` only.

## 2. Release dataset (`euro_assist_v2_release`)

Built by the existing local pipeline from the frozen snapshot into a new empty database (never by copying
`euro_assist_v2_local`, which holds 3 synthetic providers, test activity and one record edited by an earlier test):

```
legacy:preflight --snapshot-sha256=19a71fe3…  → legacy:stage --snapshot-sha256=…  → legacy:map-types --from-legacy
→ legacy:apply --actor=<temporary builder admin> --accept-provisional-types → legacy:suggest-pairs → legacy:reconcile --expect-baseline
(rc.2, after the cleanup below) → legacy:import-users   # activation policy, ADR-016: admin@euro-assist.com, Admin, placeholder password
```
then, in that database only: delete all users (FKs `SET NULL`), sessions, personal access tokens, password reset tokens,
cache, cache locks, jobs, failed jobs, job batches, import/export jobs and rows, and every audit event except
`migration.batch.staged`, `migration.batch.applied`, `provider.created`; set audit `ip_address` to NULL.
Private evidence (outside Git): `..\.work\release-20261122\` (`pipeline.log`, `cleanup.sql`, `release-counts.txt`,
`fidelity-release.txt`, `integrity-release.txt`, `fingerprint-before/after.txt`).

| Check (2026-11-22) | Result |
|---|---|
| Frozen snapshot SHA-256 | `19a71fe301c1038b52473b76bec64d2a95ca606dd701c4a2054c72f5bb791814` (matches) |
| Source rows accounted | 2,197 / 2,197 (2,188 mapped, 9 held, 0 rejected, 0 unprocessed) |
| Providers / translations / featured / types | 2,185 / 2,185 / 3 / 30 |
| Open pair suggestions / provisional rows / held rows | 334 / 972 / 9 (7 "Eye Center", 2 map links) |
| Field fidelity, 13 fields × 2,185 rows + featured names | 0 differences |
| Integrity (≠1 translation, locale mismatch, duplicate maps, orphan types, held-with-map, mapped-without-map) | all 0 |
| Users in the release DB | rc.1: 0; **rc.2: 1** (`admin@euro-assist.com`, Admin, activation placeholder, not the legacy hash) |
| Sessions, tokens, reset tokens, jobs, cache in the release DB | all 0 |
| Legacy audit DB and `euro_assist_v2_local` checksums before vs. after | identical |

Dump: `mariadb-dump --single-transaction --skip-add-drop-table --skip-routines --skip-triggers --no-tablespaces` with
the release DB's own least-privilege account; the MariaDB "sandbox" first line is replaced by a provenance header so
MySQL clients accept the file. No `DROP`, `CREATE DATABASE` or `USE` statements.

## 2b. Release dataset rc.3 (`euro_assist_v2_release_rc3`, 2026-11-23, ADR-017/018)

Same pipeline into a new empty database, then: `legacy:import-users` (activation) → `legacy:apply-review-manifest
<private manifest> --expect-sha256=f356c3ab… --actor=admin@euro-assist.com --report=<outside repo>` →
`legacy:import-media <legacy archive root> --actor=admin@euro-assist.com` → `legacy:reconcile --expect-baseline` →
cleanup (`.work/migration-review-20261123/cleanup-rc3.sql`: removes builder/QA accounts, sessions, tokens, transient rows,
non-migration audit events, IPs; keeps `admin@euro-assist.com` and the migration audit trail). Private evidence:
`.work/migration-review-20261123/` (pipeline log, manifest/media reports, integrity and fidelity results, undo rehearsal).

| Check (rc.3) | Result |
|---|---|
| Source rows accounted | 2,197 / 2,197 (2,195 mapped, 2 held "Eye Center" rows, 0 rejected) |
| Live providers / merge shells / real deleted | 1,402 / 790 / 0 |
| Providers with both languages / one language | 790 / 612 |
| Pairs applied / held for review | 790 / 104 (plus 110 manifest holds and 186 rows outside unique links, all unchanged) |
| Provisional type rows / approved aliases / row decisions | 0 / 972 rows (4 labels) / 7 (5 optics, 2 map links) |
| Open pair suggestions | 195 |
| Field fidelity 13 fields × 2,192 source rows | 0 differences except the 2 intentionally repaired map links |
| Integrity (shared maps, translations, locales, discarded values, orphans, open suggestions on paired rows) | all 0 |
| Media | 5 provider photos, 3 featured logos, 30 type icons (8 distinct V1 icons); 0 missing references |
| Undo of all 790 pairs on a disposable copy | full re-split, 0 fidelity differences |

The builder takes `--media-dir <folder with provider-photos/, type-icons/, featured-logos/>` and refuses unless the
files are exactly the media the data SQL references. The data-SQL guard is an allowlist parser (review R6).

> **2.0.0-rc.4 (2026-11-24)** is the same dataset and application as rc.3 with corrected IT documentation: the rc.3 SQL and media were copied
> byte-for-byte into `release-20261124-rc4/` and packaged with `--data-sql ...DATA_2.0.0-rc.4.sql --media-dir ...`.

## 3. Building the ZIP

```bash
python scripts/release/build_release.py --version <version> --out <private folder outside the repo> \
    --data-sql <path>/EuroAssist_Providers_V2_DATA_<version>.sql --php <php 8.3> --composer <composer.phar>
```
The builder refuses a dirty tree and an output folder inside the repository, exports the commit with `git archive`,
runs `composer install --no-dev --optimize-autoloader` (APP_ENV=packaging), `npm ci && npm run build`, removes
development-only files (tests, CI, UAT launchers, synthetic seeder, phpunit/phpstan configs, node_modules), refuses
`.env`, dumps, logs, keys, spreadsheets or `.git` in the application folder, adds `deploy/it-package/*`, the SQL,
`MANIFEST.json` (path, bytes, SHA-256 per file, git commit) and `SHA256SUMS.txt`, then writes a deterministic ZIP and
`<zip>.sha256`.

## 4. Verification performed on the built ZIP (not on the source tree)

1. ZIP SHA-256 equals the `.sha256` file; `testzip` OK; one top-level folder; no absolute or `..` paths.
2. Every line of `SHA256SUMS.txt` verified; no unlisted files.
3. Content audit: no `.env`, `.git`, `node_modules`, tests, logs, keys, spreadsheets, provisioned passwords, UAT
   launchers, synthetic seeder, environment caches; no bcrypt/argon hashes or private keys in any text file.
4. Hosting essentials present: `providers/public/index.php`, `web.config`, `.htaccess`, `build/manifest.json`, logos,
   `vendor/autoload.php`, Composer platform check (PHP ≥ 8.3.0), `bootstrap/cache/packages.php`.
5. SQL restored into an empty disposable database (`euro_assist_v2_install_rehearsal`): all counts as in §2, 25 FK
   constraints, integrity queries 0, and `CHECKSUM TABLE` of providers, translations, staging rows and id maps
   identical to the release DB. Re-importing into the now non-empty database fails at the first `CREATE TABLE`
   (exit 1) and changes nothing.
6. Extracted app configured from `production.env.example`: boots with `APP_ENV=production`, Debug OFF;
   `migrate --force` → "Nothing to migrate"; `users:provision --generate` refused in production (hidden prompt
   required); config/route/view/event caches build; schedule lists prune jobs and the every-minute queue drain;
   `schedule:run` drains the queue.
7. HTTP (production mode): `/up` 200; `/.env`, `/../composer.json`, `/storage/logs/…`, `/vendor/autoload.php` 404;
   API without token 401; foreign `Host` header 400; `X-Frame-Options: DENY`, `nosniff`, CSP `frame-ancestors 'none'`;
   session cookie `secure; httponly; samesite=lax`. Maintenance mode `down` → 503, `up` → 200.
8. Browser and HTTP E2E on the same extracted package and database with `APP_ENV=staging` and an http `APP_URL`
   (production forces an https root URL, which a local http server cannot serve): failed login message; Admin login;
   dashboard figures; create → edit (revision 2) → delete → restore with confirmation dialogs and audit entries;
   Arabic RTL search (406 results, no overflow at 1440 and 375 px); export of 1,103 Arabic rows by the real queue
   worker → dry-run import of that file: 1,103 unchanged, 0 errors, 0 applied; role route matrix (anonymous, Admin,
   Editor, Viewer × 15 routes) all as specified; disabled account refused; API token issue → reads, 404 format,
   rate-limit headers, no internal fields → revoke → 401.
9. Backup with `mysqldump --single-transaction` → restore into a scratch database → counts and checksums identical →
   scratch dropped.

**Not verifiable locally:** IIS URL Rewrite behaviour of `web.config`, Plesk PHP handler/CLI versions and extensions,
MySQL 8 import compatibility, Plesk scheduled-task execution, file permissions for the IIS application-pool identity,
the real reverse-proxy addresses, HTTPS certificate, SMTP. These are the staging checklist in
`deploy/it-package/VERIFY_AND_SMOKE_TEST.md`.
