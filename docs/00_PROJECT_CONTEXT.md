# Project context — factual baseline and project contract

**Recorded:** 2026-11-08; evidence updated 2026-11-21. **Project:** Euro Assist Providers management platform. **Sponsor / decision owner:** King.

## What King explicitly decided

1. The uploaded snapshot (`Providers.rar`, containing legacy application archive and `Providers.sql`) is the latest and final old-system baseline for this project. It will not change until V2 is ready.
2. The existing production service at `https://providers.euro-assist.com/dashboard` must remain online and **untouched**. Development occurs locally only.
3. Rebuilding/restructuring is permitted; the objective is to keep the existing business features and make the result more secure, dependable, maintainable and polished.
4. Legacy Excel import/export has caused severe incidents in production. Reliable import/export is a **core requirement**.
5. Hosting target is **Plesk without Docker**. Continue development under the assumption that its PHP/Laravel requirements can be met. Compatibility remains an explicitly unverified delivery dependency.
6. GitHub must hold source code, complete plans, architectural decisions, tasks, issue history and handoff context so work survives new chats.

## Verified from locally reviewed legacy code/dump

- Backend uses Laravel 12 / PHP constraint `^8.2`, server-rendered Blade and JS, MySQL-like SQL dump, PHPSpreadsheet and JWT package.
- Main legacy tables: `providers`, `arabic_providers`, `top_providers`, `types`, `users`, plus framework tables.
- Legacy user-facing capabilities: dashboard, Arabic/English provider management, CRUD/search/filters/pagination, Top Providers and Excel import/export; APIs expose provider searches, lat/long, distinct names and distribution stats.
- Legacy routes include `/dashboard`, `/providers/english`, `/providers/arabic`, `/excel/import`, `/excel/export/...`, `/top-providers` and `/api/...` endpoints.
- The legacy `ExcelController.php` includes `toArray()` and raises PHP memory/time limits for imports/exports; a separate root-level `upload_providers.php` exists.
- The frozen SQL snapshot was restored into a restricted, isolated local MariaDB 11.4.14 database on 2026-11-21. Exact SQL counts: 1,090 English rows, 1,104 Arabic rows and 3 Top Providers. Source checksum, table counts, quality aggregates and limitations: `docs/05_DATA_MIGRATION.md`. These are snapshot baselines, not successful V2 migration totals.
- **Important**: these counts may be bilingual duplicate entities. They are NOT 2,194 proven unique providers.

## What is NOT verified

- Current Plesk PHP versions, OS, CLI availability, Laravel Toolkit, background worker scheduling, storage limits or queue support.
- Runtime behavior and performance of the old site under load.
- Exact production API consumers or their compatibility requirements.
- Whether all Arabic/English rows have safe one-to-one matches.
- Whether logos/uploads can be legally copied to a development environment; treat as restricted until permission and handling are defined.

## Success criteria

- Zero modification to production until King authorizes a final controlled cutover.
- Feature parity against an approved capability inventory, plus modern admin UI, responsive Arabic/English interface and correct RTL.
- Safe secure permissions, audit trail, recoverable deletion and tested restore procedure.
- Large file imports do not block HTTP requests or corrupt live data. Exports do not exhaust PHP memory.
- Predictable, tested migration reconciles every row with explicit mapping or documented exception.
- Documented APIs, tests, repeatable Plesk deployment steps and rollback runbook.

## Sources inside the user's local environment only

- Original archive: `Providers.rar` (private, do not copy to Git).
- Extracted legacy app: an outer hosting-root folder and nested `providers/` Laravel application.
- Legacy DB: `Providers.sql` (private, do not copy to Git). SHA-256 of the inspected frozen input: `19a71fe301c1038b52473b76bec64d2a95ca606dd701c4a2054c72f5bb791814`.

The local source paths are environmental details; never assume other contributors have the same drive or file tree. Do not add these files to repository history.

## 2026-11-21 established state

- Private GitHub repository created and read back, seed main SHA `9d7437cb87e7a2d996dd3c506c3aadef756695f3` preserved. P0–P6 milestones and actionable task issues exist; supported dependency alerts and automatic security fixes are enabled. GitHub rejected private-repository branch protection on the current plan and reported secret scanning unavailable; PR/CI conventions are operational controls, not enforced branch protection.
- Local portable PHP 8.4.28, Composer 2.11.2, Gitleaks 8.30.1 and MariaDB 11.4.14 were downloaded from official sources with verified SHA-256 checksums outside Git. Node 24.14.1, Python 3.12.10 and Git 2.54.0 were available. No global PATH/service change or Docker dependency was introduced.
- The owner explicitly approved non-destructive blank updates and whole-file validation before imports. See ADR-008 and `docs/04_IMPORT_EXPORT_SPEC.md`; implementation remains pending.
- Read-only browser navigation to the supplied legacy `/dashboard` URL redirected to the public login page. No credentials were submitted or authenticated dashboard inspected. Local source provides the current feature inventory; live dashboard visual/interaction parity remains unverified.
