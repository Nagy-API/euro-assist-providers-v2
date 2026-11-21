# Master implementation plan — milestone-based, evidence-driven

Statuses: `TODO` (not started), `IN PROGRESS`, `BLOCKED`, `DONE` (must have evidence). Use GitHub issues to track task ownership; this document is the cross-phase reference.

## P0 — Project governance (IN PROGRESS)

- [x] Record scope, constraints, technical findings and risk register in local docs.
- [x] Build a clean, private-data-free Git seed with guidance for future chats.
- [x] Create private GitHub remote, push, verify privacy and first commit (verified 2026-11-21; see docs/14).
- [x] Create milestone/issue backlog (7 milestones, issues #1-#37). PR review workflow is operational only; hosted CI has not executed (billing blocker).

**Exit criteria:** Private GitHub repo accessible, every required doc present, no secrets or real data, repeatable contribution workflow.

## P1 — Baseline audit, requirements and reproducible local environment (IN PROGRESS; unchecked items below may be stale, see docs/01_CURRENT_STATUS.md and issues #10-#16)

- [ ] Inventory every screen, action, filter, upload, export, and API contract in old code.
- [ ] Confirm which actions must be preserved and which can be intentionally redesigned.
- [ ] Create isolated local DB, import snapshot securely, count records, validate schema, assess duplicate/mismatched providers; store only aggregated/anonymized findings in Git.
- [ ] Verify local PHP 8.3+, Composer, Node toolchain and MySQL/MariaDB. Build new Laravel skeleton plus Livewire/Alpine/Tailwind.
- [ ] Define UI design reference and responsive RTL/LTR behavior.
- [ ] Capture error modes and performance baseline using **synthetic** fixtures.
- [ ] Establish CI: unit/feature tests, formatting/static analysis, dependency checks, secret scanning.

**Exit criteria:** Signed-off feature matrix, bootable local app, documented setup steps, passing smoke test.

## P2 — Domain model and application foundation (TODO)

- [ ] Finalize providers, translations, localized addresses, provider types, featured entries, users, roles, import/export operations, audit events, legacy mappings.
- [ ] Implement migrations, factories and dummy seeders (never real dump).
- [ ] Implement login/logout, authorization policies, admin/editor/viewer roles (final matrix: ADR-010 Amendment A, `docs/08_SECURITY.md`), CSRF and rate limiting.
- [ ] Shared service layer with versioned read API; pagination, filtering, sorting.
- [ ] Logging, request correlation IDs, exception reporting and safe error pages.

**Exit criteria:** schema/tests stable, role matrix verified, services working on synthetic data.

## P3 — Reliable provider management and UI parity (TODO)

- [ ] Dashboard metrics, data tables, bilingual search/filter/sort, provider create/edit/details, bulk select and safe delete.
- [ ] Arabic and English controls with correct RTL, numeral/text alignment, mixed-language rendering, accessible forms and keyboard navigation.
- [ ] Top Providers with images, reorder/status if approved, validation and permissions.
- [ ] Usable loading/empty/error states, optimistic UI only where safe; fully responsive.
- [ ] Snapshot regression tests of critical screens and business behaviors.

**Exit criteria:** all approved old features demonstrably functional and permission-protected.

## P4 — Import/export as a first-class subsystem (TODO)

- [ ] Canonical template format with a stable schema version and column identifiers.
- [ ] Validate file type/size, map headers not positions, dry-run preview, per-row error reports.
- [ ] Idempotent upsert modes, duplicate matching, transactional chunks, rollback/reconciliation semantics.
- [ ] Asynchronous/worker-based processing with progress, cancellation and retry limits; ensure crash/restart safety.
- [ ] Export filtered/selected/all with stable IDs, bounded memory, retention policy, formula injection protection.
- [ ] Benchmark large synthetic fixtures including zero, invalid, duplicate, interrupted and mixed-language inputs.

**Exit criteria:** integration tests prove complete data consistency and bounded memory/time under agreed workloads; no destructive imports without preview/confirmation.

## P5 — Data migration and API compatibility (TODO)

- [ ] Restore immutable legacy dump into **isolated** local DB, backup and checksum it outside Git.
- [ ] Build deterministic staging transformation; compare bilingual matches via multiple evidence signals.
- [ ] Preserve legacy IDs and source table via mapping table; manually review ambiguous matches.
- [ ] Reconcile counts and important field aggregates; check missing logos/maps and type normalization.
- [ ] Capture V1 API responses (where permitted) and implement versioned compatible endpoints / adapters.
- [ ] Document contract breaks and consumer migration plan.

**Exit criteria:** 100% accounted-for legacy rows (mapped or documented exception), API parity tests pass.

## P6 — Plesk staging smoke test, UAT and cutover (TODO)

- [ ] Obtain Plesk facts: OS, PHP 8.3+, composer, extensions, CLI, scheduled tasks, queues, memory/storage limits, permissions.
- [ ] Deploy to **isolated staging** (not production), configure `public` document root, secrets through server settings, no debug.
- [ ] Validate short-running and durable jobs; define fallback if workers are unavailable.
- [ ] Validate recovery backups, web/security headers, HTTPS, rate limits, session and role safety, import/export reliability.
- [ ] UAT acceptance and stakeholder signoff.
- [ ] **Only after express approval:** schedule maintenance/cutover, final DB sync from agreed snapshot, deploy, check smoke tests, monitor and retain rollback path.

**Exit criteria:** documented passing staging tests, owner-approved cutover checklist and recovery proof. Production remains unchanged unless specifically authorized.

## 2026-11-21 execution slice — repository bootstrap and local foundation

**Goal:** preserve the seed history, verify private GitHub governance, complete a source-based legacy inventory, and establish a tested local Laravel foundation where dependencies permit.
**Architecture:** retain the existing documentation structure and use feature branches and reviewed PRs. Keep private legacy inputs and portable tooling outside this repository. Business schema, bilingual matching and API exposure remain gated by the audit and owner decisions.
**Spec:** `AGENTS.md`, this master plan, `docs/04_IMPORT_EXPORT_SPEC.md`, and `docs/15_QUALITY_GATES.md`.
**Execution:** task-sized implementation with independent review and verification under `king-dev-workflow`; continuous execution is authorized by the owner.

### Task A — Private repository and governance

- [x] Extract the seed into a separate development directory; verify clean Git status, original commit and `git fsck --full`.
- [x] Run seed path hygiene and an offline history content check; publish only the preserved seed to an empty verified private remote.
- [ ] Configure labels, phase milestones, issues with acceptance criteria/dependencies, PR settings and supported security controls; verify results and document unavailable controls.
- [ ] Harden `scripts/check_repo_hygiene.py` with portable path checks and regression tests in `tests/repository/`; add CI hygiene and redacted secret scanning without paid-action requirements.
- [ ] Independently review, test, update project memory, commit, push and open a PR. Do not merge without satisfied review requirements and authorization.

### Task B — Verified legacy inventory

- [ ] Read local controllers, routes, middleware, views, model/migration code and schema statements without executing legacy code or importing raw records.
- [ ] Update `docs/03_LEGACY_AUDIT.md`, `docs/05_DATA_MIGRATION.md` and `docs/06_API_CONTRACT.md` with source paths, parity matrix, schema/relationships, import/export mappings and verified defects.
- [ ] Separate declared routes from implemented handlers, and static findings from runtime evidence. Commit no real records, secrets or media.
- [ ] Review documentation against source; record unresolved business questions explicitly before implementation depends on them.

### Task C — Reproducible Laravel foundation

- [ ] Verify upstream Laravel/PHP/Livewire compatibility and local tool versions before installing dependencies.
- [ ] Install isolated portable development tooling only if missing, using official sources and verified checksums. Keep binaries, caches and secrets outside Git.
- [ ] Scaffold Laravel 13 without replacing governance files; add compatible Livewire/Alpine/Tailwind and database queue defaults. No provider schema or production connection yet.
- [ ] Add smoke tests for boot, safe environment, health and initial page; run formatting, static analysis, dependency audit, asset build and isolated migration checks.
- [ ] Start only on loopback; inspect the rendered page using supported browser tools. Check MySQL/MariaDB separately from SQLite test evidence.
- [ ] Independently review and verify the full foundation; update setup/status/ADR/log/handoff, commit, push and open a PR.

### Review focus and gates

- Nested environment files and Windows path separators must not bypass repository hygiene tests.
- Unavailable private-repository GitHub security/protection features must be recorded as blockers, never claimed enabled.
- Composer scripts must not inherit a legacy `.env` or connect to a non-local database.
- Declared legacy CRUD routes may lack controller implementations; parity is based on observed source behavior.
- Arabic/English IDs and empty spreadsheet cells must not trigger invented matching or destructive update semantics.

Later P2–P6 implementation remains subject to its dependency and acceptance gates above; completing this slice does not complete V2.
