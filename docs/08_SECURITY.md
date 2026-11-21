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

## Authorization matrix (owner-approved, ADR-010)

Approved 2026-11-21. Enforced server-side only; UI hiding is cosmetic. Default is **deny**. `Yes` = approved; `No` = approved denial; `TBD` = not decided, treated as **No** until the owner decides.

| Capability | Admin | Editor | Viewer |
|---|---|---|---|
| View dashboard and provider data | Yes | Yes | Yes (permitted information only) |
| Create providers | Yes | Yes | No |
| Edit providers | Yes | Yes | No |
| Delete / restore providers | Yes | TBD | No |
| Bulk operations | Yes | TBD | No |
| Featured / top providers management | Yes | TBD | No |
| Import Excel | Yes | Yes | No |
| Export Excel | Yes | Yes | No |
| View audit log | Yes | TBD | No |
| Manage users and roles | Yes | No | No |
| Manage settings / critical system configuration | Yes | No | No |

Test obligations for P2.3 (#19): a policy test for every cell above; negative HTTP/Livewire tests proving Viewer cannot reach any write, import or export route or action and Editor cannot reach user, role or settings routes; unauthenticated requests to every administrative route are rejected; no default account or embedded credential exists. Status: **documented only; not implemented** (no authentication exists in the foundation).
