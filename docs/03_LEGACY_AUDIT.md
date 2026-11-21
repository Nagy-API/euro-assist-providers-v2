# Legacy static audit (not an executed production test)

**Reading order:** the 2026-11-08 notes below are retained as audit history. The dated 2026-11-21 source inventory later in this document supersedes preliminary claims where it gives more precise evidence.

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

## 2026-11-21 — Source-based inventory of the frozen snapshot

**Scope:** read-only inspection of the hosting-root export and nested `providers/` Laravel application. Paths below are relative to that export, **not V2 source paths**. No legacy PHP, Composer scripts, migrations, HTTP endpoints or database connections were executed. SQL inspection selected only `CREATE TABLE` and `ALTER TABLE` statements; records, environment files and cached configuration were excluded. No production behavior, exploitability, row counts, rendering or load performance was tested.

Evidence labels used here and in [migration](05_DATA_MIGRATION.md) and [API](06_API_CONTRACT.md) notes:

- **Verified static:** a declaration, handler, mapping or missing check was inspected in the supplied source.
- **Inferred:** a likely consequence of that source; it still needs isolated synthetic tests.
- **Runtime unknown:** deployment, effective server configuration, actual consumers, rendered behavior and data population have not been established.

### Source map and boot caveat

| Area | Inspected source |
|---|---|
| Routing and boot | `providers/routes/{web,api}.php`, `providers/bootstrap/{app,providers}.php`, `providers/app/Http/Kernel.php`, `providers/app/Providers/{AppServiceProvider,RouteServiceProvider,AuthServiceProvider}.php` |
| Authentication | Web/API `AuthController.php`, `providers/config/auth.php`, `providers/app/Models/User.php`, middleware under `providers/app/Http/Middleware/` |
| Provider administration | Web `ProviderController.php` and `ArabicProviderController.php`; all six `providers/resources/views/providers/` views |
| Featured administration | Web/API `TopProviderController.php`, all four `providers/resources/views/top-providers/` views |
| Spreadsheet | `providers/app/Http/Controllers/ExcelController.php`, both copies of `upload_providers.php` |
| Data | Four model files, twelve migration files, schema-only statements in the local private dump |
| Browser behavior | `providers/resources/views/{index,login}.blade.php`, hosting-root and public copies of `script.js`, `bp-script.js`, `login.js` |
| Hosting entry points | Both `index.php` and `.htaccess` copies, both `time.php` copies; artifact extensions only, no archive extraction |

The configured bootstrap uses `Application::configure()->withRouting(...)` and an empty `withMiddleware` callback. `bootstrap/providers.php` lists only `AppServiceProvider`; its register/boot methods are empty. The separate old-style `App\Http\Kernel` declares web CSRF/session middleware and API throttling; `RouteServiceProvider` declares a 60/minute API limiter. Their mere presence does **not** prove they are active: the supplied framework builder binds the framework HTTP kernel, and its API limiter is conditional (`providers/vendor/laravel/framework/src/Illuminate/Foundation/Configuration/{ApplicationBuilder,Middleware}.php`). The cached routes contain `api`/`web` groups but no literal throttle entry. Effective rate limits and deployed caches remain runtime unknown. Do not describe all web routes as completely unprotected merely because `web.php` has no `auth` group: most provider/Excel handlers explicitly check `Session::has('user')`.

### Screen/action parity matrix

All rows describe **verified static implementation**, not accepted V2 scope or working runtime parity. Preserve business capabilities after owner approval; security defects and debug utilities are not parity requirements.

| Capability | Routes / handler evidence | Source behavior and parity caveat |
|---|---|---|
| Login / logout | `/`, `POST /login`, `GET /logout`; web `AuthController::{index,login,logout}` | Login validates email/password, calls `Auth::attempt`, stores `Auth::user()` in session key `user`, redirects to intended dashboard; logout calls `Auth::logout`, flushes session and redirects. Blade form includes CSRF. No registration/password-reset/user-management routes found. |
| Dashboard | `/dashboard`; `ProviderController::index`, `index.blade.php`, `script.js::{updateDashboard,fetchDistributionStats}` | Server computes English untranslated count, Arabic distinct-name and distinct-type counts; browser replaces metrics using distribution API, renders category chart/detailed breakdown and quick create/list links. `max(en, ar)` by type is an estimate, not unique entities. Browser fallback uses fetched page data and is not a trustworthy total. |
| English list | `/providers/english`, `/providers/english/paginated`, `/providers/english/filter-options`, `/providers/english/filtered-ids`; `ProviderController` | Table: name/type/city/address/district/discount/phone/translation status/actions; type/city exact filters, translation filter, broad search, sorting, pagination. JSON `{data,pagination}`; pagination fields current_page/last_page/per_page/total/from/to. Filter options `{types,cities}`; IDs `{ids,count}`. Full `/providers/list` and `/providers/types` remain. |
| Arabic list | `/providers/arabic`, `/arabic-providers/{paginated,filter-options,filtered-ids,list,types}`; web `ArabicProviderController` | Same core controls without translation filter/status. Browser falls back from paginated endpoint to full list. Arabic content is supported, but most list UI labels remain English; `edit-arabic` has explicit RTL. Full responsive/RTL correctness has not been rendered. |
| Sort/search details | Both web paginated handlers and `script.js` | `sort_column` numeric mapping: 0 id, 1 name, 2 type, 3 city, 4 address, 5 district, 6 discount_pct, 7 phone, English 8 is_translated; direction allowlisted asc/desc, default name ascending; `per_page` defaults 25 without an application cap. Search covers name/type/specialization/city/district/address/discount_pct/hours/phone/email/website/package. |
| Selection / bulk operations | List views and `script.js::{selectAllEnglishProvidersFromBackend,selectAllArabicProvidersFromBackend,exportBulkSelected,performBulkDelete}` | Per-row/page selection and backend all/filtered IDs; selection retained in browser arrays across pages. Bulk export posts `ids[]`; bulk delete posts `provider_ids` to `/bulk-delete-english` or `/arabic-providers/bulk-delete`, returns success/message/deleted_count; empty selection returns 400. Deletes are hard deletes. |
| English create | `/providers/create/en`, `POST /providers/en`; `ProviderController::{createEnglish,storeEnglish}` | Name/address required; optional type/specialization/city/district/discount/hours/phone/email/website/map/package; image jpeg/png/jpg <=2048 KB, default type logo otherwise. Package split/trim/remove empty comma entries; `is_translated=1`. Creates only English row. |
| Arabic create | `/providers/create/ar`, `POST /providers/ar`; `ProviderController::{createArabic,storeArabic}` | Creates Arabic row then separate English placeholder with copied Arabic text and `is_translated=0`; no persistent pairing ID/transaction. Placeholder omits package despite Arabic package being saved. `/create` redirects to English form; old bilingual `store` method has no route in `web.php`. |
| View / edit / delete | `/providers/{id}`, `/providers/{id}/edit`, POST or PUT update, POST `/providers/delete/{id}`; Arabic equivalents use PUT update | Detail JSON feeds modal with logo, contacts, specialization, comma-separated packages and map link. Edits use validated form fields; English checkbox controls translation flag. Locale updates/deletes affect only that locale table; no automatic matching/synchronization. Single delete returns redirect or AJAX JSON; exceptions expose their message. |
| Provider form rules | Web provider store/update handlers and four create/edit views | Name <=255, type <=50, specialization <=100, city/district/hours <=100, phone <=50, email valid <=255, website URL <=255, map URL validated, package <=255; address required string and discount free-text string. Views require type though controller permits null. No governed type table lookup is used. |
| Top Providers | Web `Route::resource('top-providers',...)`, all seven resource handlers and four views | Index/create/show/edit/store/update/destroy exist; bilingual required names <=255, English type from 30-key hardcoded mapping, derived Arabic type, optional jpeg/png/jpg/gif <=2048 KB logo. Replacement/delete unlinks old logo; hard delete. Independent table: no canonical provider relation, rank, reorder/status, bulk action or search handler found. |
| Spreadsheet operations | `/excel/template`, `/excel/import`, `/excel/export/{english,arabic}`, selected POST variants | All/filtered/custom-column/selected XLSX exports; 13-column create template; insert/update import; optional Arabic-to-English placeholder. Exact mappings and divergent standalone importer are below. No queue/progress/cancel/retry/checkpoint service found. |
| API | `providers/routes/api.php`, four API controllers | Public read handlers, JWT-style login/logout and declared-but-unimplemented write resource actions. See [API contract](06_API_CONTRACT.md); do not advertise CRUD API parity. |
| Diagnostics / auxiliary pages | `/test-excel`, `/test-db`, `/test-arabic-paginated`, `/test-arabic-db`; hosting-root/public `time.php` | Excel diagnostic download; GET test insertion; Arabic diagnostic returns count/first row/schema metadata and raw errors; status diagnostic. Clock utility uses fixed initial timestamps. These are inventory entries, not approved V2 features. |
| Standalone importer | Hosting-root/public `upload_providers.php` | Multi-file browser queue, sequential requests, upload percentage, dry-run checkbox, per-file stats; bypasses Laravel. Both copies byte-identical. Its header-driven English-table upsert differs from Laravel import. |

### Exact legacy spreadsheet contracts

Source: `ExcelController::downloadTemplate` (line 513), `generateExcel` (384), import dispatcher (615), create helpers (752/802). Positions below are **zero-based row indexes**, independent of display labels. The importer discards row 1 without checking headers; it never maps by header name.

| Index | Create template label -> field | Default all/filtered/selected export label -> field | Update importer assignment |
|---|---|---|---|
| 0 | Name* -> name | ID (DO NOT EDIT) -> id | lookup ID |
| 1 | Type -> type | Name -> name | name |
| 2 | Specialization -> specialization | Type -> type | type |
| 3 | City -> city | Specialization -> specialization | specialization |
| 4 | District -> district | City -> city | city |
| 5 | Address* -> address | District -> district | district |
| 6 | Discount -> discount_pct | Address -> address | address |
| 7 | Working Hours -> hours | Discount -> discount_pct | discount_pct |
| 8 | Phone -> phone | Working Hours -> hours | hours |
| 9 | Email -> email | Phone -> phone | phone |
| 10 | Website -> website | Email -> email | email |
| 11 | Google Maps URL -> map_url | Website -> website | website |
| 12 | Package -> package | Google Maps URL -> map_url | map_url |
| 13 | absent | Logo Path -> logo_path | **package (mismatch)** |
| 14 | absent | Package -> package | **ignored** |

Default export fields are identical for English and Arabic and selected variants. `columns` is a comma-separated custom order; recognized extra labels include `Created At`/`Updated At`. `is_translated` has no header label and is omitted by default. Unknown column keys are skipped in header generation but still traversed for cell values, potentially misaligning columns. No field allowlist validation precedes generation.

The list export dialog is a separate shape: checked default order is `name,type,city,district,address,discount_pct,phone,email,website`; optional hours/map/logo/package (Arabic also created_at/updated_at). It includes neither ID nor specialization. Selected export in `script.js::exportBulkSelected` sends IDs only, using controller defaults. Thus the default export, column-picker export and create template are **three different layouts**.

Verified import behavior:

- Request requires `file` (`xlsx,xls`) and `type` (`english,arabic`), optional `update_mode=1`, Arabic `import_to_english=1`. No explicit file-size/row cap in handler. Active sheet loaded in full via `IOFactory::load` then `toArray`; all-empty/false-like rows are skipped with `array_filter`.
- Create mode requires nonempty indexes 0/5, allowlists 30 locale-specific types before trimming, validates optional email and website, trims fields and normalizes package commas. English create sets translation flag 1; Arabic create optionally inserts an English placeholder with package and flag 0. No deduplication/idempotency.
- Update mode decides **per row** whether index 0 is numeric/nonempty. Known ID overwrites fixed positions with trimmed strings (missing cells become empty); unknown ID becomes a row error. A nonnumeric/blank ID falls back to create parsing. This is not a strict update-only contract. Updates bypass create email/type/name/address validation and do not set translation status or write logo_path.
- Individual failures are caught and accumulated; earlier writes remain. No wrapping/chunk transaction or rollback in this controller. Arabic row can remain inserted when its English placeholder fails. Response can be HTTP 200 with `success=true`, imported/updated counts and row errors; outer parse exceptions return 500. Import counter increments after successful helper return, not after thrown row errors; it cannot reconcile partial bilingual writes.
- Filtered export applies type/city and English translation filters, **ignores text search**, loads the result fully, writes untyped `setCellValue` cells and requests no formula pre-calculation. A hidden `Lists` sheet supplies locale type/city dropdowns (template rows 2–1000; export populated range). Temporary XLSX is deleted after sending. There is no explicit string-typing/formula neutralization for contacts or user text; leading-zero/formula outcomes require synthetic spreadsheet tests.
- Export, template and import handlers request unlimited execution time and 2048M memory. These are source settings, not measured memory usage or proof of the reported outage cause.

Standalone mapping (`upload_providers.php::{build_header_index,import_excel_file}`, lines 41/106; both copies identical): all 15 normalized export headers in the middle column above are mandatory, case-insensitive after whitespace normalization. Aliases: `id`, `provider id`, `id do not edit` -> ID; `hours`, `working hour` -> Working Hours; `map url`, `google map url`, `google maps` -> Google Maps URL; `logo`, `logo url` -> Logo Path; `discount %`, `discount pct`, `discount percent` -> Discount. `Name*`/`Address*` are **not** aliases, so Laravel create templates are incompatible. Duplicate canonical headers silently resolve to the last exact match; aliases fill only a still-missing canonical entry.

The standalone importer maps ID to integer; Name/Type/Specialization/City/District/Address/Working Hours/Phone/Email/Website/Google Maps URL/Logo Path/Package to matching DB fields; Discount -> `discount_pct` by numeric extraction/float conversion; `is_translated=1`. It writes only `providers` using explicit-ID `INSERT ... ON DUPLICATE KEY UPDATE`, preserves existing values for blank cells (including discount null), permits discount zero, and forces translation status 1 on affected rows. Blank IDs or payloads are skipped; malformed nonblank IDs are cast, not validated. One transaction spans a file; no explicit catch rollback exists in the helper. `dry_run` still connects/prepares SQL but skips execute/transaction and counts parsed candidates, **not validated DB changes**. AJAX POST `action=import` returns `{ok,dry_run,file,stats:{processed,affected,skipped_no_id,skipped_empty}}`; exceptions return 400 `{ok:false,error}`. It checks filename extension, has no application auth/CSRF check or row-wise create validation, and includes inline DB configuration (values deliberately excluded here).

### Static findings and corrections to preliminary risk claims

| Ref | Evidence / classification | Consequence and required V2 regression coverage |
|---|---|---|
| L-01/02 confirmed | Excel load/toArray and full collections; limit settings above | Resource-exhaustion risk inferred; benchmark bounded jobs with synthetic large/corrupt inputs. Outage causality remains unknown. |
| L-03 confirmed | Two standalone copies bypass Laravel and use different mapping, blank/discount/update semantics | Review removal/consolidation and prove legacy adapter contracts; never run either legacy script. |
| L-04 confirmed | `web.php` GET `/test-db` calls `Provider::create` in an unguarded closure; `/test-arabic-db` invokes unguarded `testArabicProviders` (line 327) | Source lacks auth and permits GET mutation / diagnostic disclosure. Actual public reachability unknown. Exclude diagnostics from V2; test unauthenticated denial. |
| L-05 strengthened | Web resource declaration plus `TopProviderController` lines 58–174: no constructor/session/auth/policy checks; base controller only authorization/validation traits; no global auth in bootstrap | Missing application authentication/authorization on featured reads/writes. Web CSRF is not identity/role authorization; server-level controls unknown. |
| L-06 corrected | Both API provider controllers have index/show/search/distinctNames/allLatLng only (English adds distribution); no store/update/destroy methods | Public read exposure verified in source. Write routes are declared **without handlers**, not proven working unauthenticated CRUD. Test explicit supported-method surface in V2. |
| L-07 refined | Dump schema has independent tables, no pairing FK; web Arabic create inserts a placeholder separately; no Eloquent relationships | Do not equate IDs, translation flag or max-count dashboard estimates with real matches. Preserve provenance and exceptions. |
| L-08 limited | Public export contains ZIP artifact; root includes SQL; `.htaccess` rewrites only non-files/non-directories and contains no archive/SQL deny rule | Exposure if those paths are served is inferred. Document root, server deny rules and actual downloads are runtime unknown; do not probe production. |
| L-09 confirmed narrowly | Standalone importer contains inline DB settings; unused demo `login.js` contains hardcoded demo authentication; private dump exists | No values copied here. Current login view comments out demo script, so it does not establish a live authentication bypass. Secret review/rotation requires owner-approved handling. |
| L-10 | `script.js` lines 747/871/1640/1979 interpolate provider values and URLs directly into `innerHTML`; controller accepts arbitrary string fields | Stored HTML/script injection sink verified; exploitable browser behavior inferred. Use escaped rendering/safe URL schemes and synthetic malicious-field tests. Same sink pattern exists in alerts/standalone filename rendering. |
| L-11 | API auth imports `Tymon\JWTAuth\Facades\JWTAuth`, installed package/autoload namespace is `PHPOpenSourceSaver\JWTAuth`; User does not implement JWTSubject; `auth.php` API driver is `token` | Multiple static JWT integration inconsistencies; runtime failure strongly inferred, not executed. Do not claim bearer login/logout compatibility. No api_token column in dump. |
| L-12 | Web provider/Excel handlers check session presence only; no role/Gate/policy calls; `AuthServiceProvider` policy map empty, `users.role` exists only in schema | Role enforcement not found in inspected application. V2 needs an explicit owner-approved permissions matrix and negative tests. |
| L-13 | Web auth login has no explicit session regeneration; logout flushes but has no explicit invalidate/regenerateToken; logout is GET | Session lifecycle/CSRF design needs tests and explicit V2 invalidation. Framework behavior must be checked independently before asserting an exploit. |
| L-14 | Arabic pagination/filtered-IDs catch blocks return exception message/trace; debug handler returns first row and metadata; deletion/import responses include raw exception messages | Source contains data/internal-error disclosure paths. Test redacted errors and authorization, retaining useful operation IDs. |
| L-15 | API search unconditionally queries `governorate`; dump has no such column in either provider table; index uses it only when filter filled | Search failure against dump schema inferred; migration history differs from dump. Resolve with synthetic isolated reproduction, not a guessed column addition. |
| L-16 | Spreadsheet positional/Logo Path mismatch, custom layouts, destructive empty updates, numeric-ID heuristic and search-ignoring exports above | Adapter tests must reproduce each mismatch; unchanged export -> dry-run import must not change package, erase absent fields or shift columns. |
| L-17 | JS calls `provider.type.replace(...)` while DB/controller allow null type; strict numeric translation checks have no model casts | Potential render exception/status mismatch; runtime untested. Synthetic null/type/boolean representations needed. |

### Remaining acceptance gaps and owner decisions

The **source inventory** is recorded; operational P1/feature parity is not complete. Pending: owner-approved preserve/redesign/retire choices (especially placeholder creation, translation semantics, featured matching and standalone import), actual API consumer inventory, sanitized synthetic contract/error fixtures, isolated DB restore/counts and bilingual/branch reconciliation, controlled import/export incident reproduction, security tests and rendered desktop/mobile/RTL review. No data model was finalized and no business matching rule was inferred from names/IDs. The old findings remain historical; L-05/L-06 and import compatibility should now be read with the precise qualifications above.
