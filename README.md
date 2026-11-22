# Euro Assist Providers System V2

> **Status: local Laravel foundation and legacy audit in progress; business features are not implemented.**
> **Owner:** King | **Repository:** [Nagy-API/euro-assist-providers-v2](https://github.com/Nagy-API/euro-assist-providers-v2) — verified private on 2026-11-21.
> **Current evidence and remaining gates:** [`docs/01_CURRENT_STATUS.md`](docs/01_CURRENT_STATUS.md).

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

## Foundation stack

Laravel 13 / PHP >= 8.3; Livewire 4 with its bundled Alpine.js; Tailwind CSS 4 and Vite; MySQL/MariaDB; database-backed queues. Native local setup and verification commands are in [`docs/07_LOCAL_SETUP.md`](docs/07_LOCAL_SETUP.md). Plesk workers/scheduled tasks remain a staging dependency; no hosting compatibility or production readiness is claimed.

## Repository state

The original documentation-first seed history is preserved. The working foundation adds `app/`, `bootstrap/`, `config/`, framework-only `database/` migrations, `public/`, `resources/`, `routes/`, `storage/` markers, PHPUnit tests, repository regression tests and CI. The home page is the upstream Laravel welcome page, **not a completed provider dashboard**. No login/roles, provider CRUD, business schema, migration pipeline, API replacement or spreadsheet subsystem is delivered yet.

The source-based audit lives in [`docs/03_LEGACY_AUDIT.md`](docs/03_LEGACY_AUDIT.md), the sanitized schema/restore evidence in [`docs/05_DATA_MIGRATION.md`](docs/05_DATA_MIGRATION.md), and API source contracts in [`docs/06_API_CONTRACT.md`](docs/06_API_CONTRACT.md). Private raw inputs, tooling and credentials stay outside this repository. Follow the [phased issue backlog](https://github.com/Nagy-API/euro-assist-providers-v2/issues) and [`docs/02_MASTER_PLAN.md`](docs/02_MASTER_PLAN.md).

## Owner acceptance testing (local)

Run `powershell -ExecutionPolicy Bypass -File .\start-uat.ps1` and follow `docs/16_UAT_CHECKLIST.md`. It uses the isolated, resettable database `euro_assist_v2_uat` only (`reset-uat.ps1`, `stop-uat.ps1`).

## Quick start for a new conversation

Provide the private repository link and ask: **“Read AGENTS.md, docs/01_CURRENT_STATUS.md, docs/10_DECISIONS.md, docs/11_WORK_LOG.md and docs/12_HANDOFF.md; inspect recent commits and open issues; continue from the next uncompleted task without touching production.”**

## References

- Laravel docs: https://laravel.com/docs
- Plesk docs: https://docs.plesk.com/
- PHPSpreadsheet: https://phpspreadsheet.readthedocs.io/
- GitHub documentation: https://docs.github.com/
