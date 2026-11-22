# Current project status — update after EVERY task

**Date:** 2026-11-22. **Phase:** P2–P5 implemented locally as a stacked draft (PR #40); nothing merged; owner decisions D1–D8 still provisional.
**Remote:** https://github.com/Nagy-API/euro-assist-providers-v2 — PRIVATE.
**Branch:** `feature/p1-foundation-hardening` (PR #40, stacked on PR #38 / `feature/p0-governance-bootstrap`). See the PR head for the latest SHA; the recovery checkpoint is `f5e5a81`.
**Hosted CI:** NOT EXECUTED — account billing blocker (`docs/14_GITHUB_BOOTSTRAP.md`). All evidence below is LOCAL.
**Production:** untouched (no login, deploy, DB or DNS action). **Plesk:** not verified.

## Verified locally (2026-11-22, Windows, portable tools)

| Check | Result |
|---|---|
| PHPUnit SQLite, PHP 8.3.37 and PHP 8.4.28 | 479 tests / 2788 assertions OK |
| PHPUnit full suite on isolated MariaDB 11.4.14 (`euro_assist_v2_test`) | 479 tests / 2788 assertions OK |
| Pint / Larastan level 6 / `composer validate --strict` | clean |
| `composer audit --locked` / `npm audit` / `npm run build` | 0 advisories / 0 vulnerabilities / OK |
| Repo hygiene, Python repo tests (23), Gitleaks on staged change, `git diff --check` | clean |
| Browser smoke (local dev DB, synthetic data) | see below |

Browser-tested on `php artisan serve` with the local dev DB `euro_assist_v2_local` (synthetic seeder, provisioned test accounts): login failure message and success; dashboard; provider list + live Livewire search; create provider (javascript: URL rejected, valid save); provider detail; Arabic RTL switch (dir=rtl, no horizontal overflow); export request -> database queue worker -> private .xlsx download (HTTP 200); Viewer and Editor route/nav access matching the ADR-010 matrix. Import upload was NOT browser-tested (file chooser); it is covered by feature tests.

## Workstreams

| Workstream | State | Open |
|---|---|---|
| Auth/roles (ADR-010) | Login, reset, throttling, inactive users, last-admin, per-action re-authorization, Livewire persistent active check | owner: "clear a value" permission (Admin-only default) |
| Providers UI | Dashboard, list/search/sort/filters, create/edit (expected_revision concurrency), soft delete/restore, bulk ops with single-use tokens, types, featured (logo upload re-encoded), users, audit log, system status; AR/EN + RTL | UI reference parity review (P1.6) |
| Import/export (ADR-008) | Template, chunked xlsx parsing, whole-file validation, staging/preview, confirm, resumable apply with atomic lock, error reports, private exports with formula safety, retention prune commands, benchmark command | Owner UAT with real-shaped files; zip-bomb guard hardening (suspicion S1) |
| API `/api/v2` | Read-only, Sanctum tokens with expiry (`api:token`), allow-listed resources, contract snapshot tests, OpenAPI `docs/openapi/v2.yaml` | Consumer contracts P5.3 unverified |
| Legacy migration | stage / map-types / suggest-pairs / apply / reconcile / report / rollback commands, pair review UI; never writes to source | Rehearsal against the frozen restore not yet run in this session |
| Security review R2 | CHANGES_REQUIRED findings M1, L1–L4 fixed with regression tests (ADR-013) | Suspicions S1–S3 open; re-review pending |

## Immediate next actions

1. Owner: fix GitHub billing so hosted CI runs; review PR #38 then PR #40. No merge without approval.
2. Owner: decisions D1–D8 (`docs/05_DATA_MIGRATION.md`), the clear-value permission, migration review in production (S3).
3. Engineering: S1 zip-bomb byte cap in `XlsxRowStream`; legacy rehearsal against the restored snapshot (P5.2); browser import flow; independent re-review of R2 fixes.
