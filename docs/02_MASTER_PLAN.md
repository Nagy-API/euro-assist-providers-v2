# Master implementation plan — milestone-based, evidence-driven

Statuses: `TODO` (not started), `IN PROGRESS`, `BLOCKED`, `DONE` (must have evidence). Use GitHub issues to track task ownership; this document is the cross-phase reference.

## P0 — Project governance (IN PROGRESS)

- [x] Record scope, constraints, technical findings and risk register in local docs.
- [x] Build a clean, private-data-free Git seed with guidance for future chats.
- [ ] Create private GitHub remote, push, verify privacy and first commit.
- [ ] Create milestone/issue backlog and PR review workflow.

**Exit criteria:** Private GitHub repo accessible, every required doc present, no secrets or real data, repeatable contribution workflow.

## P1 — Baseline audit, requirements and reproducible local environment (TODO)

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
- [ ] Implement login/logout, authorization policies, admin/editor/viewer roles, CSRF and rate limiting.
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
