# Euro Assist Providers System V2

> **Current product status — 2026-11-25: COMPLETED / LIVE / OWNER-ACCEPTED.** Final release `2.0.0-rc.6_FINAL` was deployed and tested by the owner on company infrastructure. Providers: https://providers.euro-assist.com/ · Medical Card directory: https://medicalcard.euro-assist.com/providers.php (read-only API integration). The owner provided evidence of a completed production export. Final archive manifest source: `111399000d96ebaa6d9097297f7e3c093be2a6fc`; all 6,743 manifest-listed files verified. Source application is still on stacked PRs, including [PR #52](https://github.com/Nagy-API/euro-assist-providers-v2/pull/52); the default branch is a historical documentation scaffold, not the deployed tree. GitHub repository hygiene remains a separate outstanding check. Earlier planning/no-deploy notes below are historical, not current product status. No private archive, database or production credentials are published.

> **Historical baseline (November 8):** planning/documentation scaffold, before implementation.
> **Owner:** King | **Repository:** `Nagy-API/euro-assist-providers-v2` (private intended)
> **Last factual baseline:** 2026-11-08.

A clean, maintainable replacement for the existing Euro Assist medical-provider administration platform. The public production site will remain untouched while V2 is built locally, tested independently, and eventually deployed via a controlled cutover.

## Read this first

1. [`AGENTS.md`](AGENTS.md) — mandatory instructions for a new developer/AI session.
2. [`docs/00_PROJECT_CONTEXT.md`](docs/00_PROJECT_CONTEXT.md) — goals, established facts, scope and assumptions.
3. [`docs/01_CURRENT_STATUS.md`](docs/01_CURRENT_STATUS.md) — the **only** live task-status snapshot.
4. [`docs/02_MASTER_PLAN.md`](docs/02_MASTER_PLAN.md) — phased work with exit criteria.
5. [`docs/10_DECISIONS.md`](docs/10_DECISIONS.md) — engineering decision records (ADRs).
6. [`docs/11_WORK_LOG.md`](docs/11_WORK_LOG.md) — append-only, dated session history.
7. [`docs/12_HANDOFF.md`](docs/12_HANDOFF.md) — last session handoff and next exact actions.

## Non-negotiable constraints

- V2 is developed **locally**, independently from production; no production edits or accidental database connections.
- The uploaded legacy source/archive and SQL dump are the **agreed final baseline**; production will not change during this build.
- Plesk compatibility is a **planning assumption**, not a verified property of the current subscription.
- No Docker requirement. Target a Plesk-compatible PHP/Laravel runtime with an alternate job runner if needed.
- No actual company data, credentials, `.env`, SQL dumps, provider contacts, production exports, or server archives in Git, even in a private repository.
- No feature is marked completed until code, tests/evidence, documentation and a commit exist.

## Proposed stack (decision subject to compatibility smoke test)

Laravel 13 / PHP >= 8.3; Livewire + Alpine.js; Tailwind CSS; MySQL/MariaDB; database-backed queues; Plesk-compatible scheduled tasks / queue workers. This is a design target, **not a claim that it has been installed or deployed**.

## Historical repository state (initial scaffold)

This initial package contains a **documentation-first Git scaffold**. Laravel application source, test suite and deployment artifacts are pending Phase 1; they should be added incrementally on branches and merged through PRs. Never copy the legacy project wholesale into this repository.

## Quick start for a new conversation

Provide the private repository link and ask: **“Read AGENTS.md, docs/01_CURRENT_STATUS.md, docs/10_DECISIONS.md, docs/11_WORK_LOG.md and docs/12_HANDOFF.md; inspect recent commits and open issues; continue from the next uncompleted task without touching production.”**

## References

- Laravel docs: https://laravel.com/docs
- Plesk docs: https://docs.plesk.com/
- PHPSpreadsheet: https://phpspreadsheet.readthedocs.io/
- GitHub documentation: https://docs.github.com/
