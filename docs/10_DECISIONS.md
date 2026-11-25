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

## ADR-013 — Security review R2 remediation: shared URL rule, pre-auth API limit, full credential revocation

**Date:** 2026-11-22 | **Status:** Accepted (engineering; reversible).

**Context:** Independent read-only security review R2 of the recovered integration (`f5e5a81`) returned CHANGES_REQUIRED: M1 import accepted `javascript:` website/map URLs (form did not); L1 invalid API tokens were never rate limited; L2 password reset/deactivation left API tokens (and non-database sessions) alive; L3 import lock (1800 s) shorter than the apply job timeout (3600 s); L4 private `local` disk had `serve => true`. The recovery also found host/proxy trust read with `env()` in `bootstrap/app.php`, which silently disables both guards after `config:cache`.

**Decision:**
- `App\Rules\SafeHttpUrl` is the single URL rule for the web form and the spreadsheet import (`validation_url` error code); `RowRulesParityTest` proves both accept/reject the same corpus. `/api/v2` additionally returns `null` for any stored non-http(s) `website`/`map_url` (preserved legacy data) — defense in depth.
- `ApiIpRateLimit` (per-IP ceiling, default 300/min, `PROVIDERS_API_IP_RATE_LIMIT`) runs before token authentication; it is registered in the middleware priority list before `AuthenticatesRequests`, because Laravel otherwise reorders it after authentication.
- `User::revokeAllAccess()` rotates the remember token, deletes database sessions and all personal access tokens; used on password reset and deactivation. `AuthenticateSession` is in the web group for other session drivers.
- Import lock default 3900 s (> job timeout). Private disk `serve => false`.
- Trusted hosts are a per-request closure over `config('app.url')` (exact host, no subdomains, all non-local environments); trusted proxies come from `config('app.trusted_proxies')` (`TRUSTED_PROXIES`) applied in `AppServiceProvider::boot`. Sanctum is stateless-only (`config/sanctum.php`: no stateful domains, no routes).

**Open (not decided here):** S1 xlsx decompression byte cap, S2 legacy account grants/host comparison, S3 migration review availability in production.

## ADR-014 — Legacy migration is LOCAL-ONLY with fail-closed source/destination guards; provisional type mappings

**Date:** 2026-11-22 | **Status:** Accepted (engineering; reversible). Production migration is **not** authorized by this ADR.

**Context:** Security review findings S1–S3 and the first full local rehearsal of the frozen snapshot. The existing `legacy_import` account could write to the audit database; the review screen and commands only refused `production`; 980 rows were blocked by plural/synonym type labels awaiting D3; 149 rows were blocked by an over-strict URL rule.

**Decision:**
- All migration staging, mapping, suggestion, apply, reconcile, report, rollback and pair review run only in `legacy.allowed_environments` (`local`, `testing`). The review route is not registered elsewhere. No command falls back to other credentials: the source comes only from `LEGACY_DB_*`, and `legacy.expected_source_database` has no default.
- Source: loopback host (resolved), expected database name, no URL, never equal to the destination (resolved host/port/db), and a live `SHOW GRANTS` check that refuses any privilege beyond SELECT/SHOW VIEW/USAGE or GRANT OPTION. Every read goes through `LegacySource::connection()`. A dedicated `legacy_reader` account (SELECT only) is used.
- Destination: loopback MySQL/MariaDB database on `legacy.allowed_destination_databases` (default: `euro_assist_v2_local`, `euro_assist_v2_rehearsal`, `euro_assist_v2_test`); SQLite only under `testing`.
- Type mappings gain a `provisional` status for deterministic plural/synonym labels, used only with `legacy:apply --accept-provisional-types` and tagged `provisional_type` on each row. Genuinely ambiguous labels (e.g. "Eye Center") are stored without a type and can never be resolved by a flag.
- URL rule: the apostrophe (RFC 3986 sub-delimiter, present in real DMS map links) is allowed; map links may be scheme-less like websites. Schemes other than http(s), whitespace, double quotes, angle brackets, backtick and backslash remain refused; the API documents these fields as untrusted text.
- Pair decisions require a 3–500 character reason, kept in `legacy_pair_suggestions.review_history` (not in audit metadata).
- xlsx imports: limits are enforced on bytes actually inflated (per entry and total), declared/actual size mismatch is corrupt, every entry is scanned for DTD/ENTITY, and non-UTF-8 XML is refused.

**Consequences:** A future production migration needs a new, explicitly approved ADR, environment allow-list change and operator runbook. If the owner rejects a provisional label, roll the batch back and re-apply without the flag.

## ADR-015 — Euro Assist design system, single IT delivery ZIP with the business SQL, and a rebuilt release dataset

**Date:** 2026-11-22 | **Status:** Accepted (engineering, on King's instructions of 2026-11-22). Deployment is **not** authorized by this ADR.

**Context:** King asked for (1) a frontend that belongs to the Euro Assist family of applications, (2) one practical delivery archive for IT that mirrors the legacy handoff (website folder + database SQL), with the authentic business SQL included unencrypted because King transfers it directly to authorized staff, and (3) a clean release dataset without development artefacts. The read-only inspection of the legacy archive showed Plesk on **Windows/IIS** (`web.config`, `C:\Inetpub\vhosts\…`), a nested `providers/` Laravel app whose `public/` was the document root, and a copy of that `public/` folder at the archive's top level.

**Decision:**
- **Design system:** tokens in `resources/css/app.css` taken from the approved Cards System V3 release (2026-11-08; GitHub `main` is older and has no `brand.css`, the local checkout's `brand.css` hash equals the release's). Brand red `#d10000` for actions, pure `#e60000` only in gradients; Cards ink neutrals override Tailwind `slate`; errors use a raspberry `red` scale distinct from the brand. Authentic logos copied byte-for-byte; Cairo self-hosted (OFL). Stack unchanged (Laravel + Livewire + Tailwind).
- **Release dataset:** built by running the existing local pipeline from the frozen snapshot into a NEW empty database `euro_assist_v2_release` (added to the destination allow-list), not by copying `euro_assist_v2_local`, because that preserved reference contains 3 synthetic providers, test activity and one real record edited during an earlier test. Then all users, sessions, tokens, reset tokens, cache, queue and import/export jobs and non-migration audit events are removed; migration audit events keep no actor and no IP.
- **Delivery:** one ZIP `EuroAssist_Providers_V2_<version>.zip` = `providers/` (application, production `vendor/`, built assets, `public/web.config` + `.htaccess`) + `database/…sql` + IT guides + `MANIFEST.json` + `SHA256SUMS.txt`, built only by `scripts/release/build_release.py` from a clean commit. The legacy top-level copy of `public/` is **not** reproduced: the document root must be `providers/public`.
- The SQL has no `DROP`/`CREATE DATABASE`/`USE` statements, so importing into a non-empty database fails instead of overwriting (verified).
- The ZIP and SQL never enter Git; the earlier "separately encrypted data package" requirement is superseded by King's clarification.

**Consequences:** the ZIP contains real provider contact data and must be handled as confidential (private channel, deletion after import). Production still requires: Plesk staging verification, a fresh data snapshot rebuilt with the same pipeline (`deploy/it-package/FRESH_DATA_SYNC.md`), V1 API consumer decision (D7), owner decisions on provisional types/held rows (D3), and King's written cutover approval.

## ADR-016 — Preserve legitimate legacy staff identities; password policy is an explicit owner decision

**Date:** 2026-11-22 | **Status:** Accepted 2026-11-23 — King chose the **activation** policy (identity and Admin role preserved; historical hash not transferred; live site untouched). Supersedes, for staff identities only, ADR-015's "no accounts in the release SQL".

**Context:** V2 replaces an operational system whose administrator `admin@euro-assist.com` must keep access. The frozen snapshot holds exactly one account (role `super_admin`, bcrypt cost 12). The release dataset had excluded all accounts. Evidence: `docs/19_LEGACY_ACCOUNTS.md`.

**Decision:**
- `legacy:import-users` (local-only, SELECT-only source, idempotent) imports legacy identities: e-mail, name, verification date; roles `super_admin`/`admin` → Admin, `editor` → Editor, `viewer` → Viewer, others held; existing V2 e-mails are never touched; remember tokens never copied.
- Password policy `activation` (default, recommended): the fixed placeholder `User::ACTIVATION_REQUIRED_PASSWORD` (never verifies; see Amendment); the account is activated by `users:set-password` (hidden prompt) or the reset e-mail. Policy `preserve-hash`: the legacy bcrypt hash is copied unchanged only if the V2 hasher accepts it; chosen only by explicit owner decision.
- The legacy hash is read only under `preserve-hash` (`LegacySource::accounts(true)`); `users` is not on the generic source allow-list.

**Consequences:** a revised release (data SQL + ZIP) is required to ship the identity. The fresh-data sync must re-run the import and report the account count. Setting a migrated account's password is an authorized cutover step, never done silently.
- Amendment (2026-11-23): an account awaiting activation stores the fixed placeholder `User::ACTIVATION_REQUIRED_PASSWORD` (a syntactically valid bcrypt cost-12 string that can never verify; it is not derived from any password) instead of a random hash, so the release SQL guard can refuse every other hash and the Users screen can show "Activation pending".

## ADR-017 — Apply an owner-reviewed migration manifest only after re-verification on V2; merge shells; per-row decisions

**Date:** 2026-11-23 | **Status:** Accepted (King's instruction 2026-11-23: "apply only the matches that pass the final verification, leave doubtful cases unmerged").

**Context:** An external, read-only review of the frozen `Providers.sql` (SHA-256 `19a71fe3…1814`) produced a decision manifest (private, not in Git): 894 strict EN/AR pair proposals, 110 held pairs, 4 type aliases (972 rows), 7 "Eye Center" rows (5 optics proposals, 2 holds) and 2 malformed map links. Its own validator passed. The manifest itself says the same Google Maps link and the coordinates parsed from it are ONE piece of evidence, and that it must be re-verified on V2.

**Decision:**
- New command `legacy:apply-review-manifest` (`ReviewManifestApplier`, local-only like every `legacy:*` command). It refuses a manifest from another snapshot, a wrong `--expect-sha256`, a non-Admin actor, and a report path inside the repository. Every proposal is re-checked on V2; failing ones are left untouched and reported with reason codes (ids and codes only, never values).
- Pair checks: unchanged source rows (canonical row fingerprint identical to the reviewer's tool), byte-identical map link with the manifest fingerprint, both rows mapped and unpaired, providers unedited since migration (revision 1) and still equal to the normalized source, one translation each, **no canonical conflict at all** (type, phone, e-mail, website, map, coordinates, logo, active), map link and pin used by no third record (including held rows), and **independent branch evidence**: corroborated city correspondence (≥2 reviewed pairs, dominant both ways), district positively corroborated (stable correspondence, a refinement of it, or a transliteration match of consonant skeletons), compatible address numbers, no competing open suggestion based on the same location.
- Merges go only through `PairReviewService::approve` with a per-pair reason (reversible with `undo`). The merged-away Arabic provider becomes a **merge shell**: soft-deleted with `providers.merged_into_id`; shells are excluded from deleted counts, the deleted filter, restore (policy) and exports; opening one redirects to the merged provider; `undo` clears the marker.
- Type aliases are approved in `legacy_type_mappings` only if the target type exists, the source count still matches, and the Arabic counterparts of reviewed pairs classify the same branches with the target type (no contradiction). Rows keep their raw label; reason becomes `type_alias_approved`.
- Per-row decisions (`legacy_row_decisions`: type key or repaired map link for ONE staged row, with the SHA-256 of the replaced raw value, reason, manifest hash, actor) are consumed by `LegacyApplier`; the raw staging payload never changes.
- The read-only legacy connection now uses session time zone `+00:00`: without it MariaDB converted the dump's UTC TIMESTAMPs to the server's Africa/Cairo time, so staged raw `created_at/updated_at` differed from the source (found by the row fingerprints; business fields were unaffected).
- Legacy staff user id mapping (review R5): documented exception — the only legacy account is identified by e-mail (unique), the numeric legacy id is not referenced anywhere in V2.

**Result on the isolated rebuild (`euro_assist_v2_release_rc3`):** 790 pairs applied, 104 held (98 district not corroborated, 3 city not corroborated, 1 city missing, 3 disjoint address numbers), 4 aliases approved (972 rows), 7 row decisions (5 optics + 2 map links), 2 Eye Center rows still held and visible. Undo of all 790 rehearsed on a separate copy with full re-split and 0 fidelity differences.

**Consequences:** the 104 held pairs, the 110 manifest holds, the 186 rows outside unique links and the 2 Eye Center rows stay as separate, visible records for human review; their count is shown on the Admin migration status. The fresh-data sync must re-run the pipeline and this command against the new snapshot only with a manifest made from that snapshot.

## ADR-018 — Legacy media and provider photos

**Date:** 2026-11-23 | **Status:** Accepted (owner issue #43; supersedes the "media deferred" part of D8).

**Decision:**
- `providers.logo_path` stays the raw legacy reference (fidelity contract). V2 shows `providers.photo_path` (private disk, `provider-photos/<uuid>.<ext>`), `featured_providers.logo_path` and the new `provider_types.icon_path` (`type-icons/…`).
- `legacy:import-media <archive>` reads only `<archive>/uploads/logos/` by plain file name (no traversal, no other folder, no symlink, never a URL fetch), validates the decoded image type/dimensions/size and re-encodes through the existing image store; never overwrites an existing picture; missing/invalid files are reported, never replaced. Each photo/logo gets its own stored copy (removing one never removes another); type icons may share one.
- V1 parity: V1 showed a per-type fallback icon (8 dedicated icons, `clinics.png` for every other type). These become the type icons; a row that only references its own type icon gets no photo. Display order: own photo → type icon → neutral placeholder.
- Provider photo upload/replace/remove on the provider page for users who may update the provider (upload entry points authorized too); JPEG/PNG/WebP ≤ 2048 KB (V1 limit), served only through `providers.photo` / `types.icon` to users with `providers.view`.
- The release builder takes `--media-dir` and refuses unless the media files are exactly the set referenced by the data SQL (uuid names, magic bytes, three folders, no links); files are placed in `providers/storage/app/private/`.

**Consequences:** the 40 legacy references are accounted for: 27 own-type icon references (shown via the type icon), 5 Arabic references of merged pairs (the English row's picture is used), 5 provider photos and 3 featured logos copied; 0 missing. The API does not expose photos (unchanged contract). A photo change bumps the provider revision (an edit form open at the same time reports a conflict). Production needs PHP `gd` with JPEG/PNG/WebP (without it uploads are stored validated but not re-encoded). Known follow-ups (accepted, review R6): `legacy:rollback` does not delete media files copied by `legacy:import-media` or uploaded later (orphan files only, no data exposure); the picker keeps the last chosen file name after a save.

## ADR-019 — Pre-activated Admin with a temporary password that must be changed at first sign-in

**Date:** 2026-11-24 | **Status:** Accepted (King's instruction 2026-11-24: "ready-to-use Admin login ... a newly generated strong temporary password ... must be changed before production use"). Amends ADR-016 for the release SQL (ADR-016's activation placeholder remains the default of `legacy:import-users`).

**Context:** With the activation placeholder (ADR-016) IT had to run an interactive console command before anyone could sign in. King asked for a ready-to-use login for the IT handoff while keeping the password out of the ZIP and Git.

**Decision:**
- The release SQL stores, for `admin@euro-assist.com` only, the **bcrypt hash (cost 12)** of a freshly generated 28-character temporary password (about 160 bits, never the localhost preview password) and `users.must_change_password = 1`. The plaintext is written by the build helper straight to a protected file outside the repository and outside the ZIP (Windows ACL: current user only); it is never printed, logged or committed, and it is sent to IT through a different channel than the ZIP.
- New migration `add_must_change_password_to_users_table`. `EnsurePasswordIsChanged` (route alias `password.fresh`, also a persistent Livewire middleware) lets a flagged account use only `GET/POST /account/password` and sign-out/language switch; every other web route redirects there, JSON and Livewire requests get 403. The change needs the temporary password again, a new password of 12+ characters with letters and numbers that differs from it, and confirmation (throttled 6/min); on success the flag is cleared, other sessions and remember cookies of the account end, and `auth.password_changed` is audited. A password reset or `users:set-password` also clears the flag. The Users screen shows a "Temporary password" badge. The API (separate tokens) is unaffected.
- Release builder: any bcrypt hash in a data SQL is still refused unless `--temp-admin-email <email>` is given, in which case **exactly one** `$2y$` cost 12+ hash is accepted, only as the password of that account's users row with `must_change_password = 1` and no remember token; the rule is covered by must-refuse tests.
- The pre-activation is a build step on a copy of the release database (schema migrated, only that users row changed; business tables verified identical by `CHECKSUM TABLE`); it is not audited because the audit trail of the data set is part of the verified counts (2,999 events).

**Consequences / risks:** between the import and the first sign-in, anyone who obtains the temporary password can sign in as Admin; IT is told to sign in immediately, over HTTPS, before the site is reachable by others, and to keep the password out of tickets and chat. The SQL (confidential anyway) now contains one password hash; with a 28-character random password offline cracking is not a practical risk. Every rebuild repeats the step with a NEW temporary password (FRESH_DATA_SYNC.md).

## ADR-020 - Administrator-managed accounts without e-mail

**Date:** 2026-11-25 | **Status:** Accepted (King's production feedback scope, issue #49).

**Context:** Production has no reliable e-mail delivery. Invitations and "Forgot password" depended on it.

**Decision:** No e-mail verification, invitation or recovery exists. `UserService::create` takes name, syntactically valid unique e-mail (mailbox need not exist; `email:rfc`, no DNS check), one of three roles, password + confirmation (min 12, letters and numbers) and an optional "must change at first sign-in" flag (default on). `UserService::resetPassword` (Admin, not for oneself) replaces the hash, sets the flag, and calls `User::revokeAllAccess()` (web sessions, remember tokens, API tokens); the audit event never contains the password. Self-service change is `GET/POST /account/password` (current password required, other devices signed out) and stays the only screen a flagged account can use. The `forgot-password` / `reset-password` routes, controller and views were deleted (404). Exactly three roles remain; `users.role = super_admin` (legacy) is read as Administrator by the `UserRole` cast (any other unknown value = no permissions, never promoted), counted by the last-administrator rule, and rewritten to `admin` by an idempotent data-only migration that audits the previous value. Existing users and password hashes are untouched. The session concurrency behaviour (`AuthenticateSession`, `logoutOtherDevices`) is unchanged.

**Consequences:** administrators must hand passwords over out of band; a forgotten password needs an administrator. `ops:check` reports legacy/unknown roles.

## ADR-021 - Public read-only V1-compatible API

**Date:** 2026-11-25 | **Status:** Accepted (issue #46). Supersedes the "public read OFF until D7" default **for these paths only**; `/api/v2` stays token-only.

**Decision:** see `docs/20_LEGACY_API_COMPAT.md`. GET-only routes under `/api` backed by V2 data, V1 shapes, ids preserved through `legacy_id_maps`, no write verbs, no JWT login, no speculative governorate, kill switch and per-IP rate limit.

**Consequences:** provider business data (already public on V1) is public again; images are served publicly by id. Mobile compatibility is unverified (no app available).

## ADR-022 - Queue/scheduler hardening and read-only deployment self-check

**Date:** 2026-11-25 | **Status:** Accepted (issues #47, #48).

**Decision:** (1) The scheduled worker uses `withoutOverlapping(5)`: the default lock lives 24 hours, so one killed worker could leave every job queued for a day. (2) `queue.connections.database.retry_after` 90 -> 3700 s (above the longest job): with 90 s a long export/import was redelivered to a second worker and failed. (3) The scheduler writes a heartbeat; the Export page warns when an export has been queued for more than 5 minutes. (4) `php artisan ops:check` (read-only, no secrets) reports environment/debug/config cache, database target and required tables, role values, route exposure (migration review in production = FAIL), scheduler heartbeat, stuck jobs and a SHA-256 comparison of the deployed code with `release-manifest.json` (`ops:manifest`, build-time).

**Evidence classification:** *server configuration* = no/incorrect Plesk scheduled task, wrong PHP CLI, wrong user/permissions, wrong database (missing `cache` table makes `schedule:run` itself fail); *application defects* = the 24 h lock and the short `retry_after`. A queued export is never reported as completed.
