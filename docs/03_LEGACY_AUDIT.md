# Legacy static audit (not an executed production test)

**Reviewed 2026-11-08 from uploaded local archive.** Do not confuse code inspection with a running-site or load test.

## Code inventory

- Main Laravel code: `providers/` inside hosting-root export.
- Backend: Laravel framework `^12.0` and PHP `^8.2` in legacy `composer.json`.
- Excel: `providers/app/Http/Controllers/ExcelController.php`, PHPSpreadsheet `^4.4`; separate `upload_providers.php` at hosting root.
- HTML: Blade views under `providers/resources/views`; separate hosting-root JS/CSS/PHP front files exist.
- Routes: `providers/routes/web.php` and `providers/routes/api.php`.
- Main data: `providers`, `arabic_providers`, `top_providers`, `types`, `users` plus framework tables.

## High-risk findings to reproduce as tests

| Ref | Finding | Risk | Proposed V2 control |
|---|---|---|---|
| L-01 | Excel import uses `$worksheet->toArray()` | Memory exhaustion | Chunked/streamed reading and bounded job memory |
| L-02 | Excel paths call `set_time_limit(0)` and `ini_set('memory_limit', '2048M')` | Resource contention / runaway requests | Background jobs with enforceable limits |
| L-03 | Multiple import paths (`ExcelController`, hosting-root script) | Divergent behavior and access surface | Single audited import service and authenticated endpoint |
| L-04 | Debug routes include a GET handler that inserts a test provider | Unexpected data mutation without proper safeguards | No debug routes in production; auth + read-only GET semantics |
| L-05 | `Route::resource('top-providers', ...)` in web route file without explicit auth middleware around route definition | Potential unauthorized access | Route groups, policy authorization and security tests |
| L-06 | API `apiResource` routes appear outside protected API middleware group | Potential unauthorized write endpoints | Explicit auth policy on writes; public read endpoints reviewed |
| L-07 | Legacy SQL has distinct Arabic/English provider tables and string discount fields | Translation drift / schema limitations | Canonical provider ID + translation entities and normalized types |
| L-08 | Backup and server files present under exported hosting-root/public paths | Possible exposure if served | Never serve archives, secure document root and deny unsafe paths |
| L-09 | Legacy code and SQL contain secrets/data | High-risk accidental Git exposure | Clean-room repo, no real SQL/credentials anywhere in Git |

Notes: some findings concern missing explicit middleware and need deeper analysis of actual middleware/controller logic before assigning exploitability. Production configuration has not been verified. Don't publish exploit instructions or sensitive paths outside this private tracker.

## Behavioral feature inventory to complete

- Authentication: login, logout, session expiration, roles/permissions (verify actual behavior).
- Dashboard: count breakdowns, translation states, quick actions.
- English providers: create/edit/delete/search/filter/pagination/bulk delete.
- Arabic providers: create/edit/delete/search/filter/pagination/bulk delete.
- Top providers: create, edit, view, delete, logos, type fields.
- Excel: English/Arabic template, all/filtered/selected export, import modes/row validation.
- API: provider records/search, distinct names, map coordinates, distribution stats, top providers, JWT auth.
- Edge cases: missing phone, zero-leading phone numbers, multilingual content, long discount strings, duplicate names, missing maps.

## Open forensic questions

- Which exact import/export payloads crashed the server, and what do logs say?
- Which API clients rely on V1 response fields and sorting?
- Is legacy `users.role` enforced at controller/service level?
- Does import expect same template as export, for every export variant?
- Is Arabic/English row pairing ever established by a stable external identifier?
