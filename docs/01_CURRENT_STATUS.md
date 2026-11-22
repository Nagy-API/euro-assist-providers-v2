# Current project status — update after EVERY task

**Date:** 2026-11-22. **Stage:** local release candidate for the owner's review. Nothing merged, nothing deployed.
**Remote:** https://github.com/Nagy-API/euro-assist-providers-v2 — PRIVATE.
**Branch:** `feature/p1-foundation-hardening` = PR #40 (Draft, stacked on PR #38). Latest SHA: see the PR head (the commit containing this file).
**Hosted CI:** NEVER EXECUTED — GitHub account billing blocker. All evidence below is LOCAL.
**Production / Plesk:** untouched; Plesk capabilities unverified.

## Workstream classification (evidence-based)

| Workstream | Classification | Evidence / what is missing |
|---|---|---|
| Auth & roles (Admin/Editor/Viewer) | IMPLEMENTED — ACCEPTANCE PENDING | tests + browser route/nav checks for all 3 roles on migrated data; owner UAT pending |
| Provider management UI (list/search/filter/CRUD/concurrency/soft delete) | IMPLEMENTED — ACCEPTANCE PENDING | browser-tested on 2,185 migrated records incl. lost-update refusal; owner UAT pending |
| Arabic/English, RTL/LTR, responsive | IMPLEMENTED — ACCEPTANCE PENDING | 14 pages at 375 px in RTL with 0 overflow after fixes; no formal UI parity review vs. V1 (P1.6) |
| Featured providers, types | IMPLEMENTED — ACCEPTANCE PENDING | 3 featured + 30 types migrated; tests; owner UAT |
| Import/Export | IMPLEMENTED — ACCEPTANCE PENDING | real browser uploads (invalid blocked, valid applied), full 2,188-row export→dry-run round trip 100% unchanged, 5k–25k benchmarks, recovery/lock/IDOR tests |
| `/api/v2` | IMPLEMENTED — ACCEPTANCE PENDING | all endpoints exercised on migrated data, token revoke → 401, no internal fields; real consumers unknown (P5.3) |
| Legacy migration (local) | VERIFIED COMPLETE (local rehearsal) | 2,197/2,197 rows accounted, 0 byte diffs on 13 fields, idempotent, rollback rehearsed, source unchanged |
| Bilingual pairing | PARTIALLY COMPLETE | 334 suggestions + reasoned review workflow working; 0 pairs reviewed by the owner |
| Security review | IMPLEMENTED — ACCEPTANCE PENDING | R3 PASS_WITH_NOTES (no Critical/High/Medium); all Lows fixed; final re-review by an independent reviewer recommended before merge |
| Plesk staging readiness | NOT STARTED (checklist ready) | host facts unknown; `docs/09_PLESK_DEPLOYMENT.md` checklist |
| GitHub CI | BLOCKED | account billing |
| Owner decisions D1–D8 | BLOCKED (owner) | provisional defaults documented in `docs/05_DATA_MIGRATION.md` |

## Latest local verification (2026-11-22, final code of this session)

- PHPUnit: PHP 8.3.37 and PHP 8.4.28 (SQLite) and MariaDB 11.4.14 full suite — see the work log for the exact count of the final commit.
- Pint, Larastan level 6, `composer validate --strict`, `composer audit` (0), `npm audit` (0), `npm run build`, repo hygiene, 23 Python repo tests, Gitleaks (history + staged): clean.

## Local databases (all on isolated MariaDB 127.0.0.1:33316)

| Database | Purpose |
|---|---|
| `euro_assist_legacy_audit_20261121` | frozen legacy restore — READ ONLY via `legacy_reader` |
| `euro_assist_v2_local` | V2 dev DB containing the migrated legacy data + 3 synthetic UAT providers |
| `euro_assist_v2_rehearsal` | disposable rollback/benchmark target |
| `euro_assist_v2_test` | automated tests only |

## Next actions

1. King: run `docs/16_UAT_CHECKLIST.md` locally; answer D1–D8 and the 4 provisional type labels + "Eye Center"; fix GitHub billing; review PR #38 then #40.
2. Engineering after owner input: process the 334 pair suggestions with the owner, final independent review, then isolated Plesk staging (P6) only with written approval.
