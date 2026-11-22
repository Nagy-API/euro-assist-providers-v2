# Current project status — update after EVERY task

**Date:** 2026-11-22. **Stage:** release candidate `2.0.0-rc.1` built and rehearsed **locally**. Nothing merged, nothing deployed.
**Remote:** https://github.com/Nagy-API/euro-assist-providers-v2 — PRIVATE (verified 2026-11-22).
**Branches (stacked PRs):** `feature/release-candidate` → `feature/uat-environment` (PR #41) → `feature/p1-foundation-hardening` (PR #40) → PR #38 → `main`. Latest SHA: see the PR head.
**Hosted CI:** NEVER EXECUTED — GitHub account billing blocker. All evidence below is LOCAL.
**Production / Plesk:** untouched; Plesk staging not performed.

## Release gates (production GO requires every row = PASS)

| Gate | Status | Evidence / smallest next action |
|---|---|---|
| Source implementation and frontend redesign | PASS (local) | redesign on all screens; reviewed; tests below |
| Automated tests on final code | PASS (local) | PHPUnit 519 tests on PHP 8.3.37 and 8.4.28 (SQLite) and MariaDB 11.4.14 (1 SQLite-only skip); Pint; Larastan; composer/npm audit 0; Python repo tests; hygiene; Gitleaks full history |
| Clean authentic release dataset reconciled | PASS (local) | `docs/18_RELEASE_PACKAGE.md` §2 — 2,197/2,197 rows accounted, 0 field differences |
| Package extraction / install / restore rehearsal | PASS (local, Windows) | `docs/18_RELEASE_PACKAGE.md` §4 |
| Independent review | PASS (after fixes) | R4: frontend PASS_WITH_NOTES; package CHANGES_REQUIRED → all confirmed findings fixed in `e05e78e` (work log) |
| **Plesk staging (real host)** | **BLOCKED** | needs a staging subdomain, empty DB + user, PHP 8.3+ CLI path, IIS URL Rewrite, scheduled task; IT runs `deploy/it-package/VERIFY_AND_SMOKE_TEST.md` |
| **Production PHP/extension compatibility** | **UNVERIFIED** | legacy runs Laravel 12 (`php ^8.2`); V2 needs PHP 8.3+ |
| **Fresh data synchronization** | **NOT DONE** | SQL is the frozen snapshot; `deploy/it-package/FRESH_DATA_SYNC.md` before cutover |
| **V1 API consumers** (D7) | **UNKNOWN — release dependency** | no `/api/...` V1 compatibility layer; owner must name any system calling the old API |
| **Business classifications** (D3 + held rows) | **OPEN (owner)** | 972 provisional-type rows (4 labels), 7 "Eye Center" + 2 map-link rows held; 334 pair suggestions unreviewed (not blocking staging) |
| **Legacy staff access** (ADR-016) | **OPEN (owner) — release requirement** | snapshot has 1 account (`admin@euro-assist.com`, legacy `super_admin` → Admin); import tool + activation tested on an isolated DB (`docs/19_LEGACY_ACCOUNTS.md`); King must choose the password policy (recommended: activation); then a **revised release** (SQL + ZIP) is required |
| Hosted CI | BLOCKED (billing) | local manual CI documented in `docs/15_QUALITY_GATES.md` |
| Owner cutover authorization | NOT GIVEN | written approval from King |

**Verdict:** `2.0.0-rc.1` is suitable for an **isolated Plesk staging** trial once King authorizes it, but it does NOT yet carry the legacy administrator identity; a revised release candidate is required before production.
Production: **NO-GO** until the BLOCKED/UNVERIFIED/NOT DONE/UNKNOWN rows above are closed.

## Local databases (isolated MariaDB 127.0.0.1:33316)

| Database | Purpose |
|---|---|
| `euro_assist_legacy_audit_20261121` | frozen legacy restore — READ ONLY via `legacy_reader` |
| `euro_assist_v2_local` | preserved migration reference (contains 3 synthetic providers and test activity; not for release) |
| `euro_assist_v2_release` | clean release dataset (source of the SQL in the ZIP) |
| `euro_assist_v2_install_rehearsal` | disposable: restored from the packaged SQL for the installation rehearsal |
| `euro_assist_v2_uat` | owner UAT copy (temporary QA accounts deactivated 2026-11-22; `reset-uat.ps1` restores the baseline) |
| `euro_assist_v2_rehearsal`, `euro_assist_v2_test` | rollback rehearsal / automated tests |

## Release artefacts (private, outside Git)

`..\.work\release-20261122\out\EuroAssist_Providers_V2_2.0.0-rc.1.zip` — built from `c6b7826`, 13,355,454 bytes,
SHA-256 `a8962fce2dc3540116c1fe817f0772cbcd15b3c82ac0ea912771f45f40ed9e5e` (also in `<zip>.sha256`). The earlier build
from `771a6cf` is kept only under `out\superseded-771a6cf\` and must not be delivered.
