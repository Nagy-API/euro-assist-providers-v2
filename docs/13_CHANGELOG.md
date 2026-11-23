# Changelog

This log describes **delivered application behavior**, not planned features. Keep a separate project/work log for process activity.

## Unreleased

- 2026-11-23 (release candidate 2.0.0-rc.2, not deployed): the legacy administrator `admin@euro-assist.com` is preserved with the Admin role and must be activated with `php artisan users:set-password` (hidden prompt); the old password is not transferred. Users screen shows "Activation pending". New `legacy:import-users` for the fresh-data sync.

- 2026-11-22 (release candidate 2.0.0-rc.1, not deployed): Euro Assist visual identity across every screen (ink sidebar, logo, Cairo, red accents, restrained motion, reduced-motion support); branded split-screen login with password visibility toggle; dashboard hero, overview and a "what these numbers mean" section separating V2 records, imported legacy rows, unpaired bilingual records and reviewed pairs; readable language chips ("عربي ✓ / English —"); provider filters fold on phones; provider form states which language is being edited; IIS `web.config` for Plesk on Windows; one IT delivery ZIP with the migrated business SQL and installation, verification, backup/rollback and fresh-data guides.

- 2026-11-22: owner UAT environment — `start-uat.ps1` / `stop-uat.ps1` / `reset-uat.ps1` run the app locally against an isolated, resettable copy of the migrated data; `php artisan uat:check` refuses any other database.

- 2026-11-22: legacy migration tooling is local-only with SELECT-only source checks (`legacy:preflight`); provisional type mappings (`--accept-provisional-types`); pair review requires a reason and shows held source rows; Admin dashboard shows the migration backlog.
- 2026-11-22: provider city/district filters match either language; Excel imports reject archives that expand beyond limits, DTD/entity declarations and non-UTF-8 XML; map links may be scheme-less and contain apostrophes; phone layouts no longer scroll sideways; production refuses insecure cookies and wildcard proxies; login is also limited per account; deleted-record counts are Admin-only.

- 2026-11-22 (PR #40, local only): admin UI (dashboard, providers CRUD/search/bulk, types, featured providers, users, audit log, system status) in English/Arabic with RTL; Excel import with whole-file validation, preview and confirmation; private Excel exports; read-only token API `/api/v2`; legacy migration commands and pair review.
- Security: import and form share one http(s)-only URL rule; API never returns non-http(s) links; per-IP API limit before authentication; password reset and deactivation revoke sessions and API tokens; host/proxy trust survives `config:cache`; private files are never served over HTTP.

- Added a local Laravel 13 foundation with the upstream welcome page and `/up` health endpoint; Livewire assets included. Provider/admin features and final UI remain pending.
- Added framework session/cache/queue migrations with an empty seeder and isolated smoke/infrastructure tests. No default user is created.

### Documentation

- 2026-11-08: initialized architecture, migration, security, import/export, Plesk, API and workflow documentation for future development.
- 2026-11-21: verified private remote/preserved seed, created phase/task backlog, expanded source/schema/API audit and isolated aggregate evidence, documented native setup and approved import policies. Added repository regression tests, redacted history scanning and application CI; hosted execution still needs verification.
