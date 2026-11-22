# Architecture decision records (append-only)

Use `ADR-XXX`, date, status, context, decision, alternatives, consequences and revisit trigger. Never silently rewrite a prior accepted choice; supersede it.

## ADR-001 — Frozen V1 and untouched production

**Date:** 2026-11-08 | **Status:** Accepted by King.

**Decision:** Uploaded source and SQL are the final V1 baseline. V1 Production continues to run unmodified. V2 development uses a separate local environment and a controlled eventual cutover.

**Why:** Avoid data drift and outages during redesign. **Consequence:** Deployment/migration rehearsals must use isolated environments.

## ADR-002 — GitHub as durable project memory

**Date:** 2026-11-08 | **Status:** Accepted by King; remote creation pending.

**Decision:** Private `Nagy-API/euro-assist-providers-v2` as source of code, issues, decisions, status, work logs and AI handoff notes. Every task must update documentation and link its implementation/test evidence.

**Why:** Preserve context across conversations and collaborators. **Consequence:** Documentation changes are part of Definition of Done, not optional.

## ADR-003 — Candidate platform for V2

**Date:** 2026-11-08 | **Status:** Provisional target; compatibility assumed, not verified.

**Decision:** Prefer Laravel 13 / PHP >= 8.3, Livewire, Alpine.js, Tailwind CSS, MySQL/MariaDB and Plesk-compatible worker/scheduler. No Docker dependency.

**Alternatives considered:** Fix legacy Laravel in place; rewrite in Node.js/React; rewrite in Django/React. A separate new backend incurs a higher operational rewrite without established functional need.

**Revisit trigger:** failed local compatibility checks or explicit Plesk staging evidence; avoid redesign based on guesses.

## ADR-004 — Build import/export as separate resilient services

**Date:** 2026-11-08 | **Status:** Accepted direction, implementation pending.

**Decision:** Template schema with header mapping, staging/preview, per-row validation, controlled batch transactions, resumable jobs, idempotence and chunked export. Keep implementation independent of the queue runner.

**Consequence:** More initial engineering effort but much lower risk of taking down web requests or corrupting records.

## ADR-005 — Canonical provider + translations

**Date:** 2026-11-08 | **Status:** Proposed, final schema pending real-data matching audit.

**Decision:** Consider a canonical provider entity with separate locale translations and a legacy mapping table. Preserve both Arabic/English source rows during migration.

**Non-negotiable:** Numeric ID equality and same name do not prove a record pair. Uncertain matches require review.

## ADR-006 — Protect private data from Git

**Date:** 2026-11-08 | **Status:** Accepted.

**Decision:** Legacy dump/source secrets/real provider records and customer media stay out of all Git commits, even if repository is private. Use only schema notes and synthetic fixtures.

**Why:** Security and privacy; private repositories do not make copied credentials safe.

## ADR-007 — Native isolated runtime and PHP compatibility floor

**Date:** 2026-11-21 | **Status:** Accepted implementation of the existing target; application verification in progress.

**Context:** Git, Python and Node were available; PHP and Composer were absent from PATH and common installation directories. Official Laravel 13 documentation and the upstream skeleton require PHP >=8.3.

**Decision:** Use official portable PHP 8.4.28 and Composer 2.11.2 outside the repository, with per-command PATH/configuration and verified SHA-256 downloads. Keep Composer's target PHP platform at 8.3.0 so a development machine on 8.4 cannot silently resolve dependencies requiring 8.4. Use Livewire's bundled Alpine instead of loading it twice. Provision a separate portable MariaDB 11.4.14 on loopback with database-scoped local, test and audit accounts; install no Windows service and make no production connection.

**Alternatives:** globally install a development stack; require Docker; use only SQLite. **Consequences:** no global environment changes or Docker dependency; SQLite tests and MariaDB evidence must be reported separately. The PHP 8.3 dependency floor is not proof that a real PHP 8.3 runtime or Plesk passed; CI/staging must establish that evidence.

**Sources:** [Laravel 13 requirements](https://laravel.com/framework/docs/deployment), [official PHP Windows metadata](https://downloads.php.net/~windows/releases/releases.json), [Composer downloads](https://getcomposer.org/download/), [Livewire installation](https://livewire.laravel.com/docs/4.x/installation), [official MariaDB metadata](https://downloads.mariadb.org/rest-api/mariadb/11.4.14/). Revisit on failed compatibility tests or verified hosting constraints.

## ADR-008 — Non-destructive blank updates and whole-file validation

**Date:** 2026-11-21 | **Status:** Accepted explicitly by King in this session.

**Context:** Spreadsheet updates can accidentally clear existing provider information, and valid-row-only imports create a business decision about incomplete data. The owner was presented both choices before implementation.

**Decision:** An absent column or empty update cell preserves the current value. Clearing data requires a separate explicit operation. Any validation error blocks application of the entire file until corrected and revalidated; do not apply a valid subset. Required values in create mode still require validation.

**Alternatives rejected:** empty cells implicitly clear data; apply valid rows while rejecting invalid rows. **Consequences:** preview/error reports must show all rejects without canonical writes. Once a fully validated file starts applying, bounded transactional chunks and deterministic checkpoints remain necessary for infrastructure interruptions. This is a whole-file validation gate, not a promise of global transaction atomicity. Interrupted partially applied jobs must remain visibly incomplete and resumable.

**Tests required:** blank/absent update fields preserve data; explicit clearing is distinct; one invalid row prevents all canonical writes; no apply action bypasses validation; interruption/retry does not duplicate committed effects. See `docs/04_IMPORT_EXPORT_SPEC.md` and GitHub tasks P4.1–P4.5. Revisit only with an explicit owner-approved contract change.

## ADR-009 — Provider identity represents a service location

**Date:** 2026-11-21 | **Status:** Accepted explicitly by King; detailed schema still pending.

**Context:** The isolated snapshot contains repeated names/contact values and no bilingual FK. Phone equality yields 259,947 candidate join pairs, not unique matches. A corporate brand can operate multiple branches.

**Decision:** Each distinct branch/service location has its own canonical provider identity with Arabic/English translations. Preserve branch distinctions; uncertain legacy bilingual associations require manual review. Numeric ID equality, name equality and shared phone alone never authorize a merge.

**Alternative rejected for the initial model:** a corporate parent as the primary entity with a separately managed branch hierarchy. **Consequences:** no forced corporate grouping, no automatic uncertain deduplication, and explicit source-table/source-ID mapping. Translation-field ownership, type vocabulary, nullable values, featured relationships and detailed migrations still need review; this decision does not approve an entire schema or mark ADR-005 fully implemented. Revisit only on evidence that a corporate hierarchy is required and a separately approved migration plan.


## ADR-010 — Three-role authorization model (Admin, Editor, Viewer)

**Date:** 2026-11-21 | **Status:** Accepted explicitly by King (owner); implementation pending in P2.3 ([#19](https://github.com/Nagy-API/euro-assist-providers-v2/issues/19)).

**Context:** The frozen legacy application never enforced `users.role` (audit finding L-12: session presence only, empty policy map). V2 needs an explicit permission matrix enforced on the server.

**Decision:** Exactly three roles. Matrix (details and default-deny rules in `docs/08_SECURITY.md`):

- **Admin:** full administration: manage users and roles, manage settings, full provider management, import and export, and every other authorized administrative operation.
- **Editor:** view provider data; create and edit providers; import and export Excel; manage provider records within approved permissions. Cannot manage users, roles or critical system settings.
- **Viewer:** read-only: dashboard and permitted provider information. Cannot create, edit, delete, import, export or change settings.

**Enforcement rule:** Permissions are enforced server-side (policies/gates/middleware on every route, Livewire action, queue-dispatching action and download endpoint). Hiding UI controls is never sufficient. Anything not explicitly granted is denied.

**Previously undecided items** (Editor delete/restore, bulk operations, featured management, audit log) were resolved by the owner later the same day; see "Amendment A" below, which is now authoritative.

**Consequences:** P2.3 must ship Admin/Editor/Viewer policy tests plus negative tests (each role against each protected action and each administrative route, including unauthenticated access). P4 import/export and P3 provider features inherit the matrix. Revisit only with an explicit owner-approved change recorded as a superseding ADR.


### ADR-010 Amendment A — Final Editor permissions

**Date:** 2026-11-21 | **Status:** Accepted explicitly by King (owner). Supersedes the "not yet decided" list in the original ADR-010 text; the three roles and the server-side/default-deny rules are unchanged.

**Editor:**

- Create and edit providers: ALLOW.
- Delete and restore providers: DENY.
- Bulk create/update: ALLOW, but only through validation, preview, explicit confirmation and audit logging (no path may skip any of the four).
- Bulk delete/restore: DENY.
- Manage featured/top providers: DENY.
- Import/export Excel: ALLOW, subject to the existing data-integrity safeguards (ADR-008 whole-file validation and blank-cell preservation, checksum/idempotency, private storage).
- View global audit logs: DENY.
- View own import/export job history and error reports: ALLOW, with access controls (own jobs only; other users' jobs denied).

**Admin** retains full authorized access, including all users' job history/reports and the global audit log. **Viewer** remains strictly read-only and has no import/export job history because it cannot run jobs.

**Consequences / test obligations:** the matrix in `docs/08_SECURITY.md` is now complete (no TBD cells) and each cell maps to a named permission and a policy test. Required negative tests: Editor cannot delete, restore, bulk-delete, bulk-restore, manage featured providers, view the global audit log, manage users/roles/settings, or open another user's import/export job or error report; Viewer cannot reach any write, import, export, job, audit or settings action; a bulk write that is unvalidated, unpreviewed or unconfirmed is rejected; every bulk write and import application writes an audit event. Error reports contain provider data, so they are stored privately, served only through authenticated authorization (owner or Admin), and expire per the retention policy in `docs/04_IMPORT_EXPORT_SPEC.md`.

**Remaining minor question (not invented here):** ADR-008 requires an explicit separate operation to clear an existing value. Which role may perform that clearing operation is not stated by the owner; until decided it is available to Admin only (default deny). Tracked on #19.

## ADR-011 — Provisional canonical schema implemented behind unapproved decisions D1–D8

**Date:** 2026-11-21 | **Status:** PROVISIONAL — implemented for local development only. **D1–D8 are NOT owner-approved**; nothing here may be treated as approved, and no irreversible real-data transformation depends on it.

**Context:** The owner has not yet answered D1–D8 (docs/05 "P1.4 / P2.1 decision proposal"). Development must continue; the recommendations are reversible defaults.

**Decision (what was built, each tagged with the decision it assumes):**
- D1: `providers` holds language-independent data (type FK, phone, email, website, original `map_url`, nullable parsed `latitude`/`longitude`, raw `logo_path`, `is_active`); `provider_translations` (unique `provider_id`+`locale`) holds name, address, city, district, specialization, hours, discount text, package text as lossless strings.
- D2: city/district are localized free text; no governorate/city lookup tables, no governorate column.
- D3: 30 legacy type keys seeded as `provider_types` + `provider_type_translations`; Admin-only management; legacy raw-value mapping is versioned in `legacy_type_mappings` and unmapped/ambiguous raw values go to review, never guessed.
- D4: required = provider type, at least one translation, and `name` + `address` for each translation present; everything else nullable; a provider may have one language; `email`/`hours` are retained although unused in the snapshot.
- D5: `featured_providers` are brand-level, independent records without a provider FK; `sort_order`/`is_active` are NEW V2 capabilities, not legacy rules.
- D6: legacy rows become one canonical provider per source locale row, `unpaired`; `legacy_pair_suggestions` only suggest; pairing needs explicit Admin approval and is reversible.
- D7: API is versioned `/api/v2` with allow-listed resources; public read exposure is OFF by default (`PROVIDERS_API_PUBLIC_READ=false`); V1 consumer compatibility is unverified and not claimed.
- D8: every source row must land in exactly one reconciliation state; rollback is by `migration_batch_id`; copying legacy media is deferred.
- ADR-008 is implemented as: blank/absent values preserve; clearing is a separate `ProviderService::update(..., $clear)` operation requiring `providers.clear_values` (Admin only, default deny until the owner decides).
- `providers.revision` is an optimistic-concurrency counter bumped on every real change.
- `providers.clear_values`, `types.manage` and `migration.review` are implementation permission keys beyond the doc table; all are Admin-only.

**Consequences:** If the owner chooses differently, the affected migrations are new, reversible migrations; no destructive redesign of data is needed. The owner-decision package lists only what is still open.

## ADR-012 — Test database guard runs before any database trait

**Date:** 2026-11-21 | **Status:** Accepted (engineering).

**Context:** Independent review of PR #38 found the guard ran after `parent::setUp()`, so `RefreshDatabase` could migrate before the guard threw.

**Decision:** `tests/TestCase::refreshApplication()` builds the app, then calls `Tests\Support\TestDatabaseGuard`, which fails closed unless: environment is `testing`, config is not cached, no database URL, and the target is either SQLite `:memory:` or MySQL/MariaDB at host `127.0.0.1`, an allow-listed port (33316, 3306), database exactly `euro_assist_v2_test`, an allow-listed account (`v2_test`, `ci_test`), no unix socket. The guard is a pure function with 16 negative unit cases. `phpunit.mysql.xml` now runs the whole suite on MariaDB; CI has a MariaDB service job.

**Alternatives:** database-name substring check (rejected: bypassable). **Revisit:** if more test targets are needed, extend the allow-lists in `TestDatabaseGuard`, never loosen them to patterns.
