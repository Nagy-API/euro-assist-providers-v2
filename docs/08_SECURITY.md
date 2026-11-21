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

Status: **documented only; not implemented** (no authentication exists in the foundation).