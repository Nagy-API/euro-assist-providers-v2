# Security policy and threat-model starter

## Threats to address first

1. Unauthorized administrative CRUD/import/export actions.
2. Exposed backup `.zip`/`.rar`, SQL files, `.env`, key/certificate files, or public file uploads.
3. Insecure endpoints and debug routes that mutate state without session/role checks.
4. Malicious spreadsheets: formula injection, oversized inputs, corrupt files, macro executables and payload amplification.
5. Password/JWT/session weaknesses, CSRF and excessive endpoint access.
6. Personal/company data disclosure via Git, logs, exports or exception traces.
7. Queued job duplication, races, lost updates or data integrity errors.

## Baseline controls

- Private GitHub repo; least-privilege collaborators; branch protection, code review, dependency + secret scanning where available.
- Server-side authorization policy per write/read-sensitive action, with tests for Admin, Editor and Viewer roles.
- Distinct local/staging/production environment credentials, all stored outside Git.
- Proper `public` document root, no debug routes, `APP_DEBUG=false` on servers, no public app storage or dump downloads.
- HTTPS, secure cookie settings, rate limiting, careful CORS, prepared DB access via framework, file allowlists.
- Minimal data stored in audit logs; redact sensitive values, keep actor/action/time and affected record IDs where appropriate.
- Signed/short-lived private export URLs; retention and deletion policy for uploaded spreadsheets and reports.
- Before cutover: authorization matrix, backup recovery test, static scans, dependency patch audit, UAT and signoff.

## Must never enter source control

Production DB dump, provider names/phones/addresses from the dump, server config secrets, `.env`, Plesk credentials, `APP_KEY`, JWT keys, SQL exports, uploaded images/media without clearance, logs, private customer exports or passwords/password hashes.

Existing archive may contain secrets. If accidentally committed, removing files later is insufficient; purge Git history and rotate compromised credentials with owner's explicit approval.

## Authorization matrix (owner-approved, ADR-010 incl. Amendment A)

Final as of 2026-11-21. Enforced **server-side only** (policies/gates/middleware on every route, Livewire action, queued-job dispatch and download endpoint); UI hiding is cosmetic. Anything not listed is **denied**. No cell is undecided, except the single minor item noted below the table.

| Permission key (proposed identifier) | Capability | Admin | Editor | Viewer |
|---|---|---|---|---|
| `providers.view` | View dashboard and permitted provider data | Yes | Yes | Yes |
| `providers.create` | Create providers | Yes | Yes | No |
| `providers.update` | Edit providers | Yes | Yes | No |
| `providers.delete` / `providers.restore` | Delete / restore providers | Yes | **No** | No |
| `providers.bulk.write` | Bulk create/update (validation, preview, confirmation and audit mandatory) | Yes | Yes | No |
| `providers.bulk.delete` | Bulk delete/restore | Yes | **No** | No |
| `featured.manage` | Manage featured/top providers | Yes | **No** | No |
| `imports.run` | Import Excel (data-integrity safeguards, ADR-008) | Yes | Yes | No |
| `exports.run` | Export Excel | Yes | Yes | No |
| `jobs.view.own` | View own import/export job history and error reports | Yes | Yes (own only) | No |
| `jobs.view.any` | View any user's job history and error reports | Yes | No | No |
| `audit.view` | View global audit logs | Yes | **No** | No |
| `users.manage` | Manage users and roles | Yes | No | No |
| `settings.manage` | Manage settings / critical system configuration | Yes | No | No |

Permission keys are proposed identifiers for implementation and tests, not additional business rules. The permission *effects* are the owner-approved part.

**Minor open item (default: Admin only):** which role may run the explicit "clear a stored value" operation required by ADR-008. Not decided by the owner; do not grant it to Editor implicitly.

**Bulk-write rule:** `providers.bulk.write` requires, in order: whole-file validation (ADR-008), a preview of inserts/updates/rejects, an explicit confirmation by the actor, and an audit event per applied job. Calling the apply step without a valid confirmed preview must fail.

**Job and error-report access:** error reports may contain provider data. Store privately, serve only via authenticated authorization (job owner or Admin), expire per retention policy. Another Editor's job returns a consistent denial (403 or 404, chosen once and tested).

**Test obligations for P2.3 (#19) and later phases:**
1. A policy test per matrix cell and role (3 roles x every key).
2. Negative HTTP and Livewire-action tests: Viewer cannot reach any write/import/export/job/audit/settings route; Editor cannot delete/restore (single or bulk), manage featured providers, view the global audit log, manage users/roles/settings, or open another user's job or error report.
3. Unauthenticated requests to every administrative route are rejected.
4. Bulk write without validation/preview/confirmation is rejected and writes nothing; applied bulk writes and imports each create an audit event.
5. No default account or embedded credential exists.

### Implementation additions (engineering, all Admin-only, default deny)

| Permission key | Purpose | Admin | Editor | Viewer |
|---|---|---|---|---|
| `providers.clear_values` | The explicit ADR-008 clear-a-value operation (owner decision still open; Admin only until decided) | Yes | No | No |
| `types.manage` | Manage provider types | Yes | No | No |
| `migration.review` | Review/approve/undo legacy bilingual pair suggestions | Yes | No | No |

Status: **implemented and tested** (`tests/Feature/PermissionMatrixTest.php` holds a hand-written copy of the matrix and fails if any cell differs; direct-call/negative tests exist in the service, UI and API suites). Independent security review R1 (2026-11-21) found no matrix defect; its Important findings (Host-header reset-link poisoning, `javascript:` URLs/path traversal in stored fields, revision not enforced) were fixed and covered by `tests/Feature/SecurityRegressionTest.php`. Known remaining minor items: forgot-password mail is sent synchronously (response-time enumeration signal), no script CSP (Livewire/Alpine inline evaluation), `$guarded=['id']` on internal job/legacy models (never filled from requests).
## Legacy migration safeguards (ADR-014, verified 2026-11-22)

- LOCAL-ONLY environments; review route absent elsewhere; benchmark command also local/testing only.
- Source: SELECT-only `legacy_reader` account (live `SHOW GRANTS` check), loopback, expected database, never the destination. The write-capable `legacy_import` account is refused.
- Destination: allow-listed loopback databases only. Source fingerprint (counts + `CHECKSUM TABLE`) unchanged after the full rehearsal.

## Independent security reviews

| Review | Scope | Verdict | Resolution |
|---|---|---|---|
| R1 (2026-11-21) | foundation | CHANGES_REQUIRED | fixed (SecurityRegressionTest) |
| R2 (2026-11-22, `f5e5a81`) | recovered integration | CHANGES_REQUIRED (M1, L1–L4, S1–S3) | M1/L1–L4 fixed (ADR-013); S1–S3 fixed (ADR-014) |
| R3 (2026-11-22, `0330c7c`) | whole application after migration work | **PASS_WITH_NOTES** — no Critical/High/Medium | all 6 Low + 3 informational items fixed or documented (see below) |

R3 follow-ups fixed with tests: DTD scan on every archive entry and non-UTF-8 XML refused; production refuses `SESSION_SECURE_COOKIE` ≠ true and `TRUSTED_PROXIES=*`; per-account login limit (30/hour) across IPs; CSP `object-src 'none'`; `ImportShow::$uuid` locked; deleted-record counts Admin-only; benchmark local-only. Documented, not changed: CSP has no `script-src` (Livewire/Alpine trade-off); API `website`/`map_url` are untrusted text for consumers (OpenAPI); a loopback SSH tunnel to a remote DB would pass the loopback check (operator control, mitigated by expected-DB/port/grant checks).
