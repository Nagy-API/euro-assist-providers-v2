# Current project status — update after EVERY task

**Date:** 2026-11-21. **Phase:** P1 in progress; P0 final review/hosted verification pending.
**Remote:** https://github.com/Nagy-API/euro-assist-providers-v2 — verified PRIVATE.
**Branch:** `feature/p0-governance-bootstrap`.
**Latest verified remote commit:** `9d7437cb87e7a2d996dd3c506c3aadef756695f3` on main (preserved seed). The recovered Codex work was committed on `feature/p0-governance-bootstrap` and pushed in the recovery session of 2026-11-21; see the open PR for the exact head SHA. Nothing is merged to `main`.
**Hosted CI:** NOT EXECUTED. Billing blocker: "recent account payments have failed or your spending limit needs to be increased" (see `docs/14_GITHUB_BOOTSTRAP.md`). Local checks only.
**Canonical local checkout:** `N:\Coding\programming\VS code\Providers\euro-assist-providers-v2` (the only Git repository; outer `.work/` and `.tools/` are private non-Git scratch and portable tools).
**Production:** no changes, login, deployment or DB connection. Read-only dashboard navigation redirected to login.
**Plesk:** assumed for planning, not verified.

| Workstream | Current evidence / boundary | Next gate |
|---|---|---|
| Governance | Private seed preserved; fsck/hygiene clean; 7 milestones and 37 phase/task issues; dependency alerts/automatic fixes enabled | Commit reviewed controls, PR and hosted CI |
| GitHub protection | Branch protection HTTP403 requires higher plan; secret scanning HTTP422 unavailable; effective push-protection state unverified | Keep private; operational PR/CI/manual review, no enforced protection claim |
| Legacy audit | Source inventory, ten-table schema, spreadsheet mappings, implemented API contracts in docs03/05/06 | Independent review; owner parity/remaining business signoff |
| Frozen data | Restricted local MariaDB restore: 1,090 English, 1,104 Arabic, 3 featured; checksum/quality aggregates in docs05 | No canonical matching or migration performed |
| Native runtime | PHP8.4.28, Composer2.11.2, MariaDB11.4.14; Node24.14.1/npm11.11.0 | PHP8.3 hosted evidence; eventual Plesk staging |
| Foundation | Laravel13.43.0, Livewire4.4.9/bundled Alpine, Tailwind4.3.5/Vite8.3.8; framework tables, empty seed | Whole-change review and independent verification |
| Implementer checks | SQLite6 tests/26 assertions, MariaDB2/17, Pint, Larastan level6, clean dependency audits/build recorded | Fresh independent evidence before acceptance |
| Browser | Upstream welcome inspected1280x800,768x1024,390x844 without horizontal overflow; health rendered; no captured warnings/errors | Browser Alpine interaction unverified; no business UI/reference parity |
| Role matrix | Owner-approved Admin/Editor/Viewer matrix final (ADR-010 + Amendment A) in `docs/08_SECURITY.md`; only the "who may clear a stored value" item is open (default Admin-only) | Implement and test policies in P2.3 (#19) |
| Domain/auth/providers | Not implemented beyond upstream framework tables; no default account/auth routes; no authorization code yet | Approved schema (P2.1) then P2.3 |
| PHP 8.3 / local verification | Recovery session ran full suite on PHP 8.3.37 and 8.4.28, MariaDB suite, audits, build, hygiene, Gitleaks: all exit 0 (details in docs07) | Hosted Linux CI evidence after billing fix; independent reviewer/verifier not yet run |
| Import/export | Contract only; approved blank preservation and whole-file validation, ADR-008 | P4 implementation/round-trip/recovery/benchmarks |
| API/migration | Source inventory/aggregate audit only | Consumers, mapping/reconciliation and versioned tests |
| Deployment | No staging/production operations | Separate staging evidence and explicit cutover approval |

## Issue map

Phase epics [P0#1](https://github.com/Nagy-API/euro-assist-providers-v2/issues/1), [P1#2](https://github.com/Nagy-API/euro-assist-providers-v2/issues/2), [P2#3](https://github.com/Nagy-API/euro-assist-providers-v2/issues/3), [P3#4](https://github.com/Nagy-API/euro-assist-providers-v2/issues/4), [P4#5](https://github.com/Nagy-API/euro-assist-providers-v2/issues/5), [P5#6](https://github.com/Nagy-API/euro-assist-providers-v2/issues/6), [P6#7](https://github.com/Nagy-API/euro-assist-providers-v2/issues/7) link task issues, acceptance criteria and dependencies. Do not close phases based on scaffolding alone.

## Immediate next actions

1. Owner: fix the GitHub billing/spending-limit blocker, then re-run the workflows and record real results. Owner: review the PR; no merge without approval.
2. Independent review of PR #38 is DONE (PASS_WITH_MINOR_NOTES, no code blocker; follow-ups in #39). Hosted CI evidence is still the merge gate. Owner decisions D1/D3/D4/D5/D6 unblock P2.1 (#17).
3. Complete P1 parity/business signoff: localized-field model, type vocabulary, roles and API consumers. Never infer identity from name/phone/numeric ID.
4. Implement P2 after its gates. P1 and P2–P6 remain open; this is not a completed V2 system.
